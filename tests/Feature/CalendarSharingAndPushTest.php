<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\OperationalNotification;
use App\Services\WebPush;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Phase 5: shared activities, reasons for moving or cancelling, change history and phone notifications. */
class CalendarSharingAndPushTest extends TestCase
{
    use RefreshDatabase;

    private int $lgu;

    private int $otherLgu;

    private User $worker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->lgu = DB::table('lgu_units')->insertGetId(['psgc_code' => 'CAL-1', 'name' => 'Synthetic Tabuk', 'province' => 'Kalinga', 'region' => 'CAR', 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $this->otherLgu = DB::table('lgu_units')->insertGetId(['psgc_code' => 'CAL-2', 'name' => 'Synthetic Tacloban', 'province' => 'Leyte', 'region' => 'Region VIII', 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $this->worker = $this->user('social_worker', 'Synthetic Organizer', $this->lgu);
    }

    private function user(string $role, string $name, ?int $lgu = null, array $extra = []): User
    {
        $user = User::factory()->create(['role' => $role, 'name' => $name, 'active' => true] + $extra);
        if ($lgu) {
            DB::table('user_lgu_assignments')->insert(['user_id' => $user->id, 'lgu_id' => $lgu, 'active' => true, 'effective_from' => now()->subDay(), 'created_at' => now(), 'updated_at' => now()]);
        }

        return $user;
    }

    private function activity(array $participants = []): int
    {
        $this->actingAs($this->worker)->post(route('tasks.store'), ['title' => 'Synthetic family meeting', 'event_type' => 'family_meeting', 'due_at' => now()->addDays(2)->format('Y-m-d\TH:i'),
            'participants' => $participants])->assertRedirect()->assertSessionHasNoErrors();

        return (int) DB::table('tasks')->latest('id')->value('id');
    }

    public function test_organizer_invites_people_in_the_area_who_see_and_answer_without_case_access(): void
    {
        $cpu = $this->user('cpu_member', 'Synthetic CPU Member', $this->lgu);
        $head = $this->user('lgu_head', 'Synthetic Head', $this->lgu);
        $outsider = $this->user('social_worker', 'Synthetic Outsider', $this->otherLgu);

        $this->actingAs($this->worker)->getJson(route('calendar.invitees', ['q' => 'Synthetic']))->assertOk()
            ->assertJsonFragment(['name' => 'Synthetic CPU Member'])->assertJsonMissing(['name' => 'Synthetic Outsider']);
        $this->post(route('tasks.store'), ['title' => 'X', 'due_at' => now()->addDay()->format('Y-m-d\TH:i'), 'participants' => [$outsider->id]])->assertSessionHasErrors('participants');

        $client = DB::table('clients')->insertGetId(['first_name' => 'A', 'last_name' => 'B', 'created_at' => now(), 'updated_at' => now()]);
        $case = DB::table('cases')->insertGetId(['case_number' => 'CAL-CASE-1', 'client_id' => $client, 'lgu_id' => $this->lgu, 'assigned_to' => $this->worker->id, 'status' => 'OPEN', 'priority' => 'normal', 'opened_at' => now(), 'offline_version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $this->post(route('tasks.store'), ['title' => 'Synthetic home visit', 'case_id' => $case, 'due_at' => now()->addDay()->format('Y-m-d\TH:i'), 'participants' => [$cpu->id, $head->id]])->assertSessionHasNoErrors();
        $task = (int) DB::table('tasks')->latest('id')->value('id');
        $this->assertSame(2, DB::table('task_participants')->where('task_id', $task)->count());
        $this->assertDatabaseHas('notifications', ['user_id' => $cpu->id, 'type' => 'calendar.invited']);

        $this->actingAs($cpu)->get(route('calendar.index'))->assertInertia(fn ($page) => $page->where('tasks.0.shared', true)->where('tasks.0.case_number', 'Case-linked activity')->where('tasks.0.case_id', null));
        $this->actingAs($cpu)->get(route('cases.show', $case))->assertForbidden();
        $this->post(route('tasks.respond', $task), ['response' => 'accepted'])->assertRedirect();
        $this->assertDatabaseHas('task_participants', ['task_id' => $task, 'user_id' => $cpu->id, 'response' => 'accepted']);
        $this->assertDatabaseHas('notifications', ['user_id' => $this->worker->id, 'type' => 'calendar.response']);
        $this->actingAs($outsider)->post(route('tasks.respond', $task), ['response' => 'accepted'])->assertNotFound();
    }

    public function test_moving_or_cancelling_needs_a_reason_and_tells_participants_supervisor_field_office_and_project_managers(): void
    {
        $cpu = $this->user('cpu_member', 'Synthetic CPU Member', $this->lgu);
        $head = $this->user('lgu_head', 'Synthetic Head', $this->lgu);
        $fo = $this->user('fo_user', 'Synthetic FO', null, ['region' => 'CAR']);
        $otherFo = $this->user('fo_user', 'Synthetic Other FO', null, ['region' => 'Region VIII']);
        $national = $this->user('project_manager', 'Synthetic National PM', null, ['region_scope' => ['*']]);
        $regional = $this->user('project_manager', 'Synthetic Region VIII PM', null, ['region_scope' => ['Region VIII']]);
        $task = $this->activity([$cpu->id]);

        $later = now()->addDays(5)->format('Y-m-d\TH:i');
        $this->patch(route('tasks.update', $task), ['title' => 'Synthetic family meeting', 'due_at' => $later, 'participants' => [$cpu->id]])->assertSessionHasErrors('reason');
        $this->patch(route('tasks.update', $task), ['title' => 'Synthetic family meeting', 'due_at' => $later, 'participants' => [$cpu->id], 'reason' => 'The family asked for a later date.'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('task_history', ['task_id' => $task, 'event' => 'rescheduled', 'reason' => 'The family asked for a later date.']);
        $audit = DB::table('audit_logs')->where('event', 'task.rescheduled')->first();
        $this->assertSame('workflow_status', $audit->log_type);
        $this->assertNotNull($audit->old_value);
        foreach ([$cpu, $head, $fo, $national] as $told) {
            $this->assertDatabaseHas('notifications', ['user_id' => $told->id, 'type' => 'calendar.rescheduled']);
        }
        foreach ([$otherFo, $regional, $this->worker] as $notTold) {
            $this->assertDatabaseMissing('notifications', ['user_id' => $notTold->id, 'type' => 'calendar.rescheduled']);
        }

        $this->patch(route('tasks.transition', $task), ['status' => 'cancelled'])->assertSessionHasErrors('reason');
        $this->patch(route('tasks.transition', $task), ['status' => 'cancelled', 'reason' => 'The child moved to another town.'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('tasks', ['id' => $task, 'status' => 'cancelled']);
        $this->assertDatabaseHas('notifications', ['user_id' => $head->id, 'type' => 'calendar.cancelled']);
        $this->assertDatabaseHas('audit_logs', ['event' => 'task.cancelled', 'new_value' => 'cancelled']);
        $this->get(route('calendar.index'))->assertInertia(fn ($page) => $page->has('tasks.0.history', 4));
    }

    public function test_phone_notifications_send_a_contentless_signed_push_and_drop_expired_subscriptions(): void
    {
        $this->actingAs($this->worker)->postJson(route('push.subscribe'), ['endpoint' => 'https://push.example.test/abc'])->assertNotFound();
        $keys = WebPush::generateKeys();
        config(['services.webpush.public_key' => $keys['public'], 'services.webpush.private_key' => $keys['private']]);
        $this->postJson(route('push.subscribe'), ['endpoint' => 'http://insecure.example.test/abc'])->assertStatus(422);
        $this->postJson(route('push.subscribe'), ['endpoint' => 'https://push.example.test/abc', 'device_label' => 'Android phone'])->assertOk();
        $this->postJson(route('push.subscribe'), ['endpoint' => 'https://push.example.test/gone'])->assertOk();

        Http::fake(['push.example.test/abc' => Http::response('', 201), 'push.example.test/gone' => Http::response('', 410)]);
        WebPush::notify($this->worker->id);
        Http::assertSent(function ($request) use ($keys) {
            if ($request->url() !== 'https://push.example.test/abc') {
                return false;
            }
            $this->assertSame('', $request->body());
            $this->assertSame('86400', $request->header('TTL')[0]);
            preg_match('/^vapid t=([^,]+), k=(.+)$/', $request->header('Authorization')[0], $m);
            $this->assertSame($keys['public'], $m[2]);
            [$head, $claims, $sig] = explode('.', $m[1]);
            $this->assertSame('https://push.example.test', json_decode(base64_decode(strtr($claims, '-_', '+/')), true)['aud']);
            // Verify the ES256 signature with the public key.
            $raw = base64_decode(strtr($sig, '-_', '+/') . '==');
            $int = fn ($v) => (ord(ltrim($v, "\0")[0] ?? "\0") > 127 ? "\0" : '') . ltrim($v, "\0");
            [$r, $s] = [$int(substr($raw, 0, 32)), $int(substr($raw, 32))];
            $der = "\x30" . chr(4 + strlen($r) + strlen($s)) . "\x02" . chr(strlen($r)) . $r . "\x02" . chr(strlen($s)) . $s;
            $point = base64_decode(strtr($keys['public'], '-_', '+/') . '=');
            $pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode(hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . $point), 64, "\n") . "-----END PUBLIC KEY-----\n";
            $this->assertSame(1, openssl_verify($head . '.' . $claims, $der, $pem, OPENSSL_ALGO_SHA256));

            return true;
        });
        $this->assertDatabaseMissing('push_subscriptions', ['endpoint' => 'https://push.example.test/gone']);
        $this->assertDatabaseHas('audit_logs', ['event' => 'push.subscribed']);

        app(OperationalNotification::class)->send($this->worker->id, 'test', 'Synthetic title', 'Synthetic body', '/calendar');
        $this->getJson(route('notifications.latest'))->assertOk()->assertJsonPath('items.0.title', 'Synthetic title');
        $this->assertStringContainsString('no-store', $this->getJson(route('notifications.latest'))->headers->get('Cache-Control'));
        $other = $this->user('social_worker', 'Synthetic Other', $this->lgu);
        $this->actingAs($other)->getJson(route('notifications.latest'))->assertJsonCount(0, 'items');
    }
}
