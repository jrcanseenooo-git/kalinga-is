<?php

namespace App\Services;

use App\Models\User;
use App\Support\PhilippineDate;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Referrals and services recorded from a progress note, as in the earlier
 * Kalinga design: the note is the narrative, the referral or service is the
 * tracked record linked to it.
 *
 * A referral to a CPU member who has a Kalinga account in the case's LGU also
 * opens that member's case access (an MDT grant) for the referral only; the
 * access ends when the referral is completed or closed.
 */
class NoteReferrals
{
    public const SERVICE_TYPES = ['Medical', 'Financial', 'Transportation', 'Legal', 'Psychosocial', 'Educational', 'Shelter / temporary placement', 'Food / relief goods', 'Livelihood', 'Other'];

    public const AMOUNT_TYPES = ['Financial', 'Medical', 'Transportation', 'Educational', 'Livelihood'];

    public const TERMINAL = ['COMPLETED', 'DECLINED', 'RETURNED', 'UNAVAILABLE', 'WITHDRAWN', 'CLOSED'];

    private const GRANT_CATEGORIES = ['case_summary', 'case_stage', 'referral_service', 'selected_case_notes'];

    public function __construct(private readonly ReferralTracking $tracking, private readonly OperationalNotification $notifications) {}

    public function referralRules(): array
    {
        return [
            'referral.referred_to' => 'required|string|max:160',
            'referral.lgu_cpu_member_id' => 'nullable|integer',
            'referral.service_category' => 'required|string|max:255',
            'referral.purpose' => 'required|string|max:160',
            'referral.target_date' => 'nullable|date|after_or_equal:' . PhilippineDate::today(),
            'referral.lawful_basis' => 'required|in:consent,legal_obligation,vital_interests,public_task',
            // Conducted from a row of the Intervention Plan (the row's key).
            'referral.plan_row' => ['nullable', 'string', 'max:40', 'regex:/^[A-Za-z0-9-]+$/'],
        ];
    }

    public function serviceRules(string $prefix = 'service.'): array
    {
        return [
            $prefix . 'service_type' => 'required|in:' . implode(',', self::SERVICE_TYPES),
            $prefix . 'specific_detail' => 'nullable|string|max:255',
            $prefix . 'amount' => 'nullable|numeric|min:0|max:9999999',
            $prefix . 'date_provided' => 'required|date|before_or_equal:' . PhilippineDate::today(),
            $prefix . 'provided_by' => 'nullable|string|max:160',
            $prefix . 'referral_id' => 'nullable|integer',
        ];
    }

    /** Must be called inside the caller's transaction. Returns the referral id. */
    public function createReferral(User $actor, object $case, int $noteId, array $data, string $occurredAt): int
    {
        if ($data['lawful_basis'] === 'consent' && !$this->tracking->hasSharingConsent((int) $case->id)) {
            throw ValidationException::withMessages(['referral.lawful_basis' => 'This case has no signed consent / assent form yet. Add the photo in Client & Intake, or choose another documented basis.']);
        }
        $member = null;
        if (!empty($data['lgu_cpu_member_id'])) {
            $member = DB::table('lgu_cpu_members')->where('id', $data['lgu_cpu_member_id'])->where('active', true)->where('lgu_id', $case->lgu_id)->first();
            if (!$member) {
                throw ValidationException::withMessages(['referral.lgu_cpu_member_id' => 'Choose an active CPU member of this case\'s LGU.']);
            }
        }

        $id = DB::table('referrals')->insertGetId([
            'case_id' => $case->id, 'service_category' => $data['service_category'], 'referred_to' => $member ? $member->name . ' (' . $member->cpu_role . ')' : $data['referred_to'],
            'purpose' => $data['purpose'], 'reason' => null, 'case_note_id' => $noteId, 'lgu_cpu_member_id' => $member?->id, 'recipient_user_id' => $member?->user_id,
            'target_date' => $data['target_date'] ?? null, 'lawful_basis' => $data['lawful_basis'], 'sharing_authorized_by' => $actor->id, 'sharing_authorized_at' => now(),
            'status' => 'SENT', 'referred_at' => $occurredAt, 'created_by' => $actor->id, 'created_at' => now(), 'updated_at' => now(),
            'plan_row_key' => $data['plan_row'] ?? null,
        ]);
        DB::table('referral_events')->insert(['referral_id' => $id, 'from_status' => null, 'to_status' => 'SENT', 'remarks' => empty($data['plan_row']) ? 'Recorded from a progress note.' : 'Conducted from the Intervention Plan.', 'actor_id' => $actor->id, 'created_at' => now()]);

        if ($member?->user_id) {
            $account = DB::table('users')->where('id', $member->user_id)->where('role', 'cpu_member')->where('active', true)->first();
            $assigned = $account && DB::table('user_lgu_assignments')->where('user_id', $account->id)->where('lgu_id', $case->lgu_id)->where('active', true)->exists();
            if ($assigned) {
                $days = (int) config('kalinga.referral_response_days') + (int) config('kalinga.referral_completion_days');
                $grant = DB::table('mdt_case_access')->insertGetId([
                    'case_id' => $case->id, 'user_id' => $account->id, 'professional_role' => mb_substr($member->position ?: $member->cpu_role, 0, 120),
                    'organization' => mb_substr($member->office ?: 'CPU', 0, 180), 'purpose' => mb_substr('Referral: ' . $data['purpose'], 0, 255),
                    'allowed_categories' => json_encode(self::GRANT_CATEGORIES), 'lawful_basis' => $data['lawful_basis'], 'status' => 'assigned',
                    'assigned_by' => $actor->id, 'assigned_at' => now(), 'access_starts_at' => now(), 'access_expires_at' => now()->addDays(max($days, 7)),
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                DB::table('referrals')->where('id', $id)->update(['mdt_case_access_id' => $grant]);
                $this->notifications->send($account->id, 'mdt_assignment', 'New referral received', 'A case was referred to you. Open it to acknowledge and record your action.', '/mdt/collaboration');
            }
        }

        return $id;
    }

    /** Ends the CPU member's referral access when the referral reaches a final status. */
    public function closeAccessIfFinal(int $referral, string $status): void
    {
        if (!in_array($status, self::TERMINAL, true)) {
            return;
        }
        $grant = DB::table('referrals')->where('id', $referral)->value('mdt_case_access_id');
        if (!$grant) {
            return;
        }
        DB::table('mdt_case_access')->where('id', $grant)->whereNotIn('status', ['completed', 'revoked', 'returned'])
            ->update(['status' => $status === 'COMPLETED' ? 'completed' : 'returned', 'completed_at' => now(), 'updated_at' => now()]);
    }

    public function recordService(User $actor, int $case, array $data, ?int $noteId = null): int
    {
        $amount = in_array($data['service_type'], self::AMOUNT_TYPES, true) && isset($data['amount']) && $data['amount'] !== '' ? $data['amount'] : null;
        if ($data['service_type'] === 'Financial' && $amount === null) {
            throw ValidationException::withMessages(['service.amount' => 'Enter the amount given for financial assistance.']);
        }
        if (!empty($data['referral_id']) && !DB::table('referrals')->where('id', $data['referral_id'])->where('case_id', $case)->exists()) {
            throw ValidationException::withMessages(['service.referral_id' => 'Choose a referral of this case.']);
        }

        return DB::table('services_provided')->insertGetId([
            'case_id' => $case, 'service_type' => $data['service_type'], 'specific_detail' => $data['specific_detail'] ?? null, 'amount' => $amount,
            'date_provided' => $data['date_provided'], 'provided_by' => $data['provided_by'] ?? null, 'referral_id' => $data['referral_id'] ?? null,
            'case_note_id' => $noteId, 'recorded_by' => $actor->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function servicesFor(int $case)
    {
        return DB::table('services_provided')->leftJoin('users', 'users.id', '=', 'services_provided.recorded_by')->where('services_provided.case_id', $case)
            ->orderByDesc('services_provided.date_provided')->orderByDesc('services_provided.id')
            ->get(['services_provided.id', 'services_provided.service_type', 'services_provided.specific_detail', 'services_provided.amount', 'services_provided.date_provided',
                'services_provided.provided_by', 'services_provided.referral_id', 'services_provided.case_note_id', 'users.name as recorded_by_name']);
    }
}
