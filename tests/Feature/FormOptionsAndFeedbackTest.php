<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\IntakeDrafts;
use App\Services\OptionSets;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Phase 7: editable dropdown lists with usage warnings, extra intake fields and user feedback. */
class FormOptionsAndFeedbackTest extends TestCase
{
    use RefreshDatabase;

    private User $worker;

    private User $pm;

    protected function setUp(): void
    {
        parent::setUp();
        OptionSets::ensureDefaults();
        $lgu = DB::table('lgu_units')->insertGetId(['psgc_code' => 'OPT-1', 'name' => 'Synthetic Town', 'province' => 'Synthetic Province', 'region' => 'Synthetic Region', 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $this->worker = User::factory()->create(['role' => 'social_worker', 'active' => true]);
        DB::table('user_lgu_assignments')->insert(['user_id' => $this->worker->id, 'lgu_id' => $lgu, 'active' => true, 'effective_from' => now()->subDay(), 'created_at' => now(), 'updated_at' => now()]);
        $this->pm = User::factory()->create(['role' => 'project_manager', 'region_scope' => ['*'], 'active' => true]);
    }

    private function intake(array $overrides = []): array
    {
        return array_replace(['privacy_notice_acknowledged' => true, 'privacy_notice_method' => 'explained_verbally', 'first_name' => 'Ana', 'last_name' => 'Cruz', 'birthdate' => '2010-05-01', 'sex' => 'Female',
            'religion' => 'Islam', 'barangay' => 'Poblacion', 'cefmu_classifications' => ['early_union'], 'union_status' => 'yes_now', 'priority' => 'normal', 'initial_risk_level' => 'low'], $overrides);
    }

    public function test_lists_are_editable_with_a_usage_warning_and_reach_the_browser(): void
    {
        $this->actingAs($this->worker)->get(route('admin.options'))->assertForbidden();
        $this->actingAs($this->pm)->get(route('admin.options'))->assertOk()->assertInertia(fn (Assert $page) => $page->component('Admin/FormOptions')
            ->where('sets', fn ($sets) => collect($sets)->pluck('key')->contains('disability_types') && collect($sets)->pluck('key')->contains('intake_risk_factors')));

        // An open case and a saved intake use "Islam".
        $this->actingAs($this->worker)->post(route('cases.store'), $this->intake())->assertSessionHasNoErrors();
        IntakeDrafts::save((string) Str::uuid(), $this->worker->id, null, 2, ['religion' => 'Islam']);
        $islam = DB::table('reference_data')->where('set_name', 'religions')->where('label', 'Islam')->value('code');
        $this->actingAs($this->pm)->getJson(route('admin.options.usage', ['religions', $islam]))->assertOk()->assertJson(['cases' => 1, 'intakes' => 1]);
        $this->putJson(route('admin.options.update', ['religions', $islam]), ['label' => 'Islam (Muslim)', 'active' => true])->assertStatus(409)->assertJsonPath('usage.cases', 1);
        $this->putJson(route('admin.options.update', ['religions', $islam]), ['label' => 'Islam (Muslim)', 'active' => true, 'confirmed' => true])->assertOk();
        $this->assertDatabaseHas('audit_logs', ['event' => 'option.updated', 'old_value' => 'Islam', 'new_value' => 'Islam (Muslim)', 'log_type' => 'system_configuration']);
        $this->assertDatabaseHas('clients', ['religion' => 'Islam']);

        $this->post(route('admin.options.store', 'ip_groups'), ['label' => 'Kalinga'])->assertSessionHasNoErrors();
        $this->post(route('admin.options.store', 'ip_groups'), ['label' => 'kalinga'])->assertSessionHasErrors('label');
        $this->post(route('admin.options.store', 'grant_types'), ['label' => 'Burial assistance'])->assertSessionHasNoErrors();
        $this->post(route('admin.options.store', 'nonexistent'), ['label' => 'X'])->assertNotFound();

        $this->actingAs($this->worker)->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
            ->where('optionSets.IP_MEMBERSHIP', fn ($options) => collect($options)->pluck('label')->contains('Kalinga'))
            ->where('optionSets.RELIGIONS', fn ($options) => collect($options)->pluck('value')->contains('Islam (Muslim)')));
        $case = DB::table('cases')->value('id');
        $this->post(route('cases.grants.store', $case), ['grant_type' => 'burial_assistance', 'amount' => 1000, 'released_at' => now()->toDateString()])->assertSessionHasNoErrors();

        // Retiring keeps saved records; the last active option cannot be retired.
        $other = DB::table('reference_data')->where('set_name', 'ip_groups')->where('label', '!=', 'Kalinga')->value('code');
        $this->actingAs($this->pm)->putJson(route('admin.options.update', ['ip_groups', $other]), ['label' => 'x x', 'active' => false, 'confirmed' => true])->assertOk();
        $this->putJson(route('admin.options.update', ['ip_groups', OptionSets::textCode('Kalinga')]), ['label' => 'Kalinga', 'active' => false])->assertStatus(422);

        $this->put(route('admin.options.risk-rule'), ['moderate' => 2, 'high' => 3, 'critical' => 5])->assertSessionHasNoErrors();
        $this->assertSame(['moderate' => 2.0, 'high' => 3.0, 'critical' => 5.0], OptionSets::riskRule());
        $this->put(route('admin.options.risk-rule'), ['moderate' => 3, 'high' => 2, 'critical' => 5])->assertSessionHasErrors('high');
    }

    public function test_extra_intake_fields_are_validated_and_saved_with_the_case(): void
    {
        $this->actingAs($this->pm)->post(route('admin.intake-fields.store'), ['label' => 'Dialect spoken', 'type' => 'select', 'options' => ['Kalinga', 'Ilocano', 'Tagalog'], 'required' => true])->assertSessionHasNoErrors();
        $this->post(route('admin.intake-fields.store'), ['label' => 'Bad dropdown', 'type' => 'select', 'options' => ['Only one']])->assertSessionHasErrors('options');
        $this->post(route('admin.intake-fields.store'), ['label' => 'School name', 'type' => 'text'])->assertSessionHasNoErrors();

        $this->actingAs($this->worker)->get(route('cases.create'))->assertInertia(fn (Assert $page) => $page->has('intakeOptions.customFields', 2)->where('intakeOptions.customFields.0.label', 'Dialect spoken'));
        $this->post(route('cases.store'), $this->intake())->assertSessionHasErrors('custom_fields.x_dialect_spoken');
        $this->post(route('cases.store'), $this->intake(['custom_fields' => ['x_dialect_spoken' => 'French']]))->assertSessionHasErrors('custom_fields.x_dialect_spoken');
        $this->post(route('cases.store'), $this->intake(['custom_fields' => ['x_dialect_spoken' => 'Ilocano', 'x_school_name' => 'Synthetic School', 'x_not_defined' => 'dropped']]))->assertSessionHasNoErrors();
        $stored = json_decode(DB::table('cases')->value('custom_fields'), true);
        $this->assertSame(['x_dialect_spoken' => 'Ilocano', 'x_school_name' => 'Synthetic School'], $stored);
        $this->get(route('cases.show', DB::table('cases')->value('id')))->assertInertia(fn (Assert $page) => $page->where('customFieldLabels.x_dialect_spoken', 'Dialect spoken'));

        $field = DB::table('form_fields')->where('key', 'x_dialect_spoken')->value('id');
        $this->actingAs($this->pm)->patch(route('admin.intake-fields.toggle', $field))->assertRedirect();
        $this->actingAs($this->worker)->post(route('cases.store'), $this->intake(['first_name' => 'Bea']))->assertSessionHasNoErrors();
        $this->get(route('admin.forms'))->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => 'system_admin']))->withSession(['two_factor_confirmed' => true])->get(route('admin.forms'))->assertRedirect(route('admin.options'));
    }

    public function test_users_send_feedback_and_reviewers_reply(): void
    {
        $this->actingAs($this->worker)->postJson(route('feedback.store'), ['category' => 'problem', 'subject' => 'Map is slow', 'message' => 'The map takes long to load on my phone.', 'page' => '/analytics?region=Secret+Search', 'rating' => 3])->assertOk();
        $this->postJson(route('feedback.store'), ['category' => 'nope', 'subject' => 'x', 'message' => 'y'])->assertStatus(422);
        $row = DB::table('user_feedback')->first();
        $this->assertSame('/analytics', $row->page);
        $this->get(route('admin.feedback'))->assertForbidden();

        $this->actingAs($this->pm)->get(route('admin.feedback'))->assertOk()->assertInertia(fn (Assert $page) => $page->where('items.data.0.subject', 'Map is slow')->where('counts.new', 1));
        $this->patch(route('admin.feedback.respond', $row->id), ['status' => 'planned', 'response' => 'We will make the map lighter.'])->assertRedirect();
        $this->assertDatabaseHas('notifications', ['user_id' => $this->worker->id, 'type' => 'feedback.reply']);
        $this->actingAs($this->worker)->getJson(route('feedback.mine'))->assertJsonPath('items.0.status', 'planned')->assertJsonPath('items.0.response', 'We will make the map lighter.');
        $this->assertDatabaseHas('audit_logs', ['event' => 'feedback.submitted']);
        $this->assertStringNotContainsString('The map takes long', (string) DB::table('audit_logs')->where('event', 'feedback.submitted')->value('changes'));
    }
}
