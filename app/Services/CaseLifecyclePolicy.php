<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Narrow, owner-configurable closure readiness rules. Defaults are conservative
 * until the LGU/FO owner approves a local closure and aftercare policy.
 */
class CaseLifecyclePolicy
{
    public function closureRules(): array
    {
        $stored = DB::table('case_lifecycle_policies')->where('scope', 'closure')->pluck('value', 'key')->all();

        return $stored + [
            'high_or_critical_risk' => 'block',
            'open_tasks' => 'warning',
            'open_referrals' => 'warning',
            'aftercare_required' => 'warning',
        ];
    }

    public function readiness(int $case): array
    {
        $rules = $this->closureRules();
        $facts = [
            'high_or_critical_risk' => DB::table('cases')->where('id', $case)->whereIn('current_risk_level', ['high', 'critical'])->exists(),
            'open_tasks' => DB::table('tasks')->where('case_id', $case)->where('status', 'open')->exists(),
            'open_referrals' => DB::table('referrals')->where('case_id', $case)->whereNotIn('status', ['COMPLETED', 'WITHDRAWN'])->exists(),
            // Kalinga (owner, 2026-10-28): an aftercare plan is written at closure; older required
            // aftercare records that are unfinished also count.
            'aftercare_required' => !Aftercare::plan($case)
                || DB::table('case_aftercare_plans')->where('case_id', $case)->whereNull('living_arrangement')->where('required', true)->whereNotIn('status', ['completed', 'not_required'])->exists(),
        ];
        $result = ['critical_blockers' => [], 'warnings' => [], 'policy_validation_required' => true];
        foreach ($facts as $key => $present) {
            if (!$present) {
                continue;
            }
            ($rules[$key] ?? 'warning') === 'block'
                ? $result['critical_blockers'][] = ['code' => $key]
                : $result['warnings'][] = ['code' => $key];
        }

        return $result;
    }
}
