<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class LguProfileAndCalendarTest extends TestCase
{
    use RefreshDatabase;

    private function lgu(string $code, string $name, string $province = 'Synthetic Province'): int
    {
        return DB::table('lgu_units')->insertGetId(['psgc_code' => $code, 'name' => $name, 'province' => $province, 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function assign(User $user, int $lgu): void
    {
        DB::table('user_lgu_assignments')->insert(['user_id' => $user->id, 'lgu_id' => $lgu, 'active' => true, 'effective_from' => now()->subDay(), 'created_at' => now(), 'updated_at' => now()]);
    }

    private function profilePayload(): array
    {
        return ['office_name' => 'City Social Welfare and Development Office', 'office_short_name' => 'CSWDO', 'head_name' => 'Synthetic Head',
            'head_position' => 'Social Welfare Officer IV', 'head_designation' => 'City Social Welfare and Development Officer'];
    }

    public function test_case_manager_manages_only_their_assigned_lgu_profile(): void
    {
        $own = $this->lgu('TEST-LGU-1', 'Synthetic City');
        $other = $this->lgu('TEST-LGU-2', 'Other Town');
        $worker = User::factory()->create(['role' => 'social_worker', 'position' => 'Social Welfare Officer II']);
        $this->assign($worker, $own);

        $this->actingAs($worker)->get(route('lgu-profile.show'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Lgu/Profile')->has('lgus', 1)->where('selectedLguId', $own)->where('canManage', true)
            ->where('caseManagers.0.position', 'Social Welfare Officer II'));

        $this->put(route('lgu-profile.update', $own), $this->profilePayload())->assertRedirect();
        $this->assertDatabaseHas('lgu_profiles', ['lgu_id' => $own, 'head_name' => 'Synthetic Head', 'updated_by' => $worker->id]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'lgu_profile.updated', 'auditable_id' => $own]);

        $this->put(route('lgu-profile.update', $other), $this->profilePayload())->assertForbidden();
        $this->post(route('lgu-profile.members.store', $other), ['name' => 'X', 'position' => 'Y', 'cpu_role' => 'Member'])->assertForbidden();

        // The signatory is required: reports need a name and position to sign.
        $this->put(route('lgu-profile.update', $own), ['office_name' => 'Office'])->assertSessionHasErrors(['head_name', 'head_position']);
    }

    public function test_cpu_members_and_logo_are_recorded_and_protected(): void
    {
        Storage::fake('local');
        $own = $this->lgu('TEST-LGU-3', 'Synthetic City');
        $worker = User::factory()->create(['role' => 'social_worker']);
        $cpu = User::factory()->create(['role' => 'cpu_member', 'cpu_discipline' => 'health_officer']);
        $stranger = User::factory()->create(['role' => 'social_worker']);
        $this->assign($worker, $own);
        $this->assign($cpu, $own);

        $this->actingAs($worker)->post(route('lgu-profile.members.store', $own), ['name' => 'Synthetic Health Officer', 'position' => 'Municipal Health Officer',
            'office' => 'Municipal / City Health Office', 'cpu_role' => 'Health representative', 'user_id' => $cpu->id])->assertRedirect();
        $member = DB::table('lgu_cpu_members')->where('lgu_id', $own)->first();
        $this->assertSame($cpu->id, (int) $member->user_id);

        // Only accounts assigned to this LGU can be linked.
        $this->post(route('lgu-profile.members.store', $own), ['name' => 'Wrong link', 'position' => 'P', 'cpu_role' => 'Member', 'user_id' => $stranger->id])->assertSessionHasErrors('user_id');

        $this->put(route('lgu-profile.members.update', [$own, $member->id]), ['name' => 'Synthetic Health Officer', 'position' => 'MHO', 'cpu_role' => 'Health representative', 'active' => false])->assertRedirect();
        $this->assertDatabaseHas('lgu_cpu_members', ['id' => $member->id, 'position' => 'MHO', 'active' => false]);

        $this->post(route('lgu-profile.logo.upload', $own), ['logo' => UploadedFile::fake()->image('logo.png', 200, 200)])->assertRedirect();
        $path = DB::table('lgu_profiles')->where('lgu_id', $own)->value('logo_path');
        Storage::disk('local')->assertExists($path);
        $this->get(route('lgu-profile.logo', $own))->assertOk();
        $this->post(route('lgu-profile.logo.upload', $own), ['logo' => UploadedFile::fake()->create('logo.pdf', 10, 'application/pdf')])->assertSessionHasErrors('logo');

        // CPU members have no profile permission; unassigned workers cannot see the logo.
        $this->actingAs($cpu)->get(route('lgu-profile.show'))->assertForbidden();
        $this->actingAs($stranger)->get(route('lgu-profile.logo', $own))->assertForbidden();

        $this->actingAs($worker)->delete(route('lgu-profile.members.destroy', [$own, $member->id]))->assertRedirect();
        $this->assertDatabaseMissing('lgu_cpu_members', ['id' => $member->id]);
    }

    public function test_field_office_user_views_lgus_in_their_region_without_editing_and_a_project_manager_manages_them(): void
    {
        $inRegion = $this->lgu('TEST-LGU-4', 'Synthetic City', 'Province A');
        $far = $this->lgu('TEST-LGU-5', 'Far Town', 'Province B');
        DB::table('lgu_units')->where('id', $inRegion)->update(['region' => 'Region A']);
        DB::table('lgu_units')->where('id', $far)->update(['region' => 'Region B']);
        $fo = User::factory()->create(['role' => 'fo_user', 'region' => 'Region A']);
        $this->actingAs($fo)->get(route('lgu-profile.show'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('lgus', 1)->where('selectedLguId', $inRegion)->where('canManage', false));
        $this->put(route('lgu-profile.update', $inRegion), $this->profilePayload())->assertForbidden();

        $national = User::factory()->create(['role' => 'project_manager', 'region_scope' => ['*']]);
        $this->actingAs($national)->get(route('lgu-profile.show'))->assertOk()->assertInertia(fn (Assert $page) => $page->has('lgus', 2)->where('canManage', true));
        $this->put(route('lgu-profile.update', $far), $this->profilePayload())->assertRedirect();
        $focal = User::factory()->create(['role' => 'project_manager', 'region_scope' => ['Region A']]);
        $this->actingAs($focal)->get(route('lgu-profile.show'))->assertOk()->assertInertia(fn (Assert $page) => $page->has('lgus', 1));
        $this->put(route('lgu-profile.update', $far), $this->profilePayload())->assertForbidden();
    }

    public function test_calendar_events_keep_type_place_and_details_and_show_case_dates(): void
    {
        $lgu = $this->lgu('TEST-LGU-6', 'Synthetic City');
        $worker = User::factory()->create(['role' => 'social_worker']);
        $this->assign($worker, $lgu);
        $client = DB::table('clients')->insertGetId(['first_name' => 'Synthetic', 'last_name' => 'Child', 'municipality' => 'Synthetic City', 'created_at' => now(), 'updated_at' => now()]);
        $case = DB::table('cases')->insertGetId(['case_number' => 'KLG-TEST-1', 'client_id' => $client, 'lgu_id' => $lgu, 'assigned_to' => $worker->id, 'status' => 'OPEN', 'stage' => 'intake',
            'priority' => 'normal', 'opened_at' => now(), 'next_review_at' => now()->addDays(2), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('case_conferences')->insert(['case_id' => $case, 'conference_type' => 'initial', 'status' => 'scheduled', 'scheduled_at' => now()->addDays(3), 'created_by' => $worker->id, 'created_at' => now(), 'updated_at' => now()]);

        $this->actingAs($worker)->post(route('tasks.store'), ['title' => 'Home visit - KLG-TEST-1', 'event_type' => 'home_visit', 'case_id' => $case,
            'due_at' => now()->addDay()->format('Y-m-d\TH:i'), 'location' => "Child's home", 'details' => 'Bring consent form'])->assertRedirect();
        $task = DB::table('tasks')->where('title', 'Home visit - KLG-TEST-1')->first();
        $this->assertSame('home_visit', $task->event_type);
        $this->assertSame("Child's home", $task->location);

        $this->post(route('tasks.store'), ['title' => 'Bad', 'event_type' => 'party'])->assertSessionHasErrors('event_type');

        $this->patch(route('tasks.update', $task->id), ['title' => 'Home visit moved', 'event_type' => 'home_visit', 'due_at' => now()->addDays(4)->format('Y-m-d\TH:i'), 'reason' => 'Family asked to move it.'])->assertRedirect();
        $this->assertDatabaseHas('tasks', ['id' => $task->id, 'title' => 'Home visit moved']);

        $this->get(route('calendar.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('tasks', 1)->where('tasks.0.event_type', 'home_visit')
            ->where('caseEvents', fn ($events) => collect($events)->pluck('type')->sort()->values()->all() === ['assessment', 'case_conference']));

        // Another worker cannot edit someone else's activity.
        $other = User::factory()->create(['role' => 'social_worker']);
        $this->actingAs($other)->patch(route('tasks.update', $task->id), ['title' => 'Hijack'])->assertForbidden();
    }
}
