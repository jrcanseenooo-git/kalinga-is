<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Grants log per case. OWNER REVIEW: the grant types and whether the fund
 * source must come from a fixed list.
 */
final class CaseGrants
{
    public const TYPES = [
        'educational' => 'Educational assistance',
        'financial' => 'Financial assistance (AICS)',
        'medical' => 'Medical assistance',
        'livelihood' => 'Livelihood / seed capital',
        'transportation' => 'Transportation assistance',
        'food' => 'Food / material assistance',
        'other' => 'Other',
    ];

    /** @return array{items: array<int, array<string, mixed>>, total: float, count: int} */
    public static function forCase(int $caseId): array
    {
        $items = DB::table('case_grants')->leftJoin('users', 'users.id', '=', 'case_grants.recorded_by')->where('case_grants.case_id', $caseId)
            ->orderByDesc('case_grants.released_at')->orderByDesc('case_grants.id')
            ->get(['case_grants.id', 'case_grants.grant_type', 'case_grants.amount', 'case_grants.released_at', 'case_grants.fund_source', 'case_grants.purpose',
                'case_grants.received_by', 'case_grants.voided_at', 'case_grants.void_reason', 'case_grants.created_at', 'users.name as recorded_by_name'])
            ->map(fn ($row) => (array) $row + ['type_label' => self::TYPES[$row->grant_type] ?? $row->grant_type, 'amount' => (float) $row->amount]);
        $active = $items->whereNull('voided_at');

        return ['items' => $items->values()->all(), 'total' => round((float) $active->sum('amount'), 2), 'count' => $active->count()];
    }

    /** Total released (not voided) for a set of cases, for dashboards. */
    public static function totalFor($caseIds): array
    {
        $query = DB::table('case_grants')->whereNull('voided_at')->whereIn('case_id', $caseIds);

        return ['amount' => round((float) (clone $query)->sum('amount'), 2), 'count' => (clone $query)->count(), 'cases' => (clone $query)->distinct()->count('case_id')];
    }
}
