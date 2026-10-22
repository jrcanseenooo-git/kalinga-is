<?php

namespace App\Services;

final class CaseVersionGuard
{
    /** Compare only after acquiring the parent case write lock. */
    public static function check(object $case, array $data): void
    {
        // Legacy callers remain compatible while workflow-specific interfaces
        // adopt the existing case version. Never compare an unlocked snapshot.
        if (isset($data['expected_version'])) {
            abort_unless((int) $case->offline_version === (int) $data['expected_version'], 409, 'This case changed. Refresh and review the current record before submitting again.');
        }
    }
}
