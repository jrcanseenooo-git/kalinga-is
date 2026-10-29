<?php

return [
    // Temporary, explicitly enabled UAT accounts only. Keep APP_DEBUG=false on Render.
    'uat_enabled' => env('UAT_ENABLED', false),
    /* Comma-separated first-party origins, only when a separate web client is approved. */
    'cors_allowed_origins' => array_filter(array_map('trim', explode(',', env('CORS_ALLOWED_ORIGINS', '')))),
    'registration_enabled' => env('REGISTRATION_ENABLED', env('APP_ENV', 'production') !== 'production'),
    'mfa_enforcement' => env('MFA_ENFORCEMENT', true),
    // Roles that must pass a second factor at every sign-in. CPU members are included
    // because referrals give them the child's case details. OWNER REVIEW.
    'mfa_roles' => array_values(array_filter(array_map('trim', explode(',', env('MFA_ROLES', 'system_admin,social_worker,fo_user,cpu_member'))))),
    'scan_driver' => env('SCAN_DRIVER', 'none'),
    'clamav_binary' => env('CLAMAV_BINARY', 'clamscan'),
    'scan_timeout_seconds' => (int) env('SCAN_TIMEOUT_SECONDS', 120),
    /*
     * Referral follow-up targets, carried over from the earlier Kalinga SLA rules
     * (REFERRAL_RESPONSE: 5 days from sending; REFERRAL_COMPLETION: 30 days from
     * acceptance). Owner review: confirm against the local referral protocol.
     */
    'referral_response_days' => (int) env('REFERRAL_RESPONSE_DAYS', 5),
    'referral_completion_days' => (int) env('REFERRAL_COMPLETION_DAYS', 30),
];
