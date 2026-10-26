<?php

namespace App\Support;

/**
 * Classifies every audit event into the 14 audit log types, a module and an
 * action, and writes a plain description. One place, so the audit page,
 * the export and new code all use the same words.
 *
 * Exact entries in EVENTS win; anything else is classified from its prefix
 * (module) and its last word (action and type).
 */
final class AuditCatalogue
{
    public const TYPES = [
        'authentication' => 'Authentication',
        'user_management' => 'User Management',
        'access_permission' => 'Access and Permission',
        'data_creation' => 'Data Creation',
        'data_modification' => 'Data Modification',
        'data_deletion' => 'Data Deletion',
        'data_access' => 'Data Access',
        'workflow_status' => 'Workflow and Status',
        'document_file' => 'Document and File',
        'report_generation' => 'Report Generation',
        'system_configuration' => 'System Configuration',
        'security' => 'Security',
        'system_error' => 'System Error',
        'integration_sync' => 'Integration and Synchronization',
    ];

    public const ACTIONS = ['LOGIN', 'LOGOUT', 'CREATE', 'UPDATE', 'DELETE', 'ARCHIVE', 'VIEW', 'SUBMIT', 'APPROVE', 'REJECT', 'REFER', 'ASSIGN', 'CLOSE', 'REOPEN',
        'UPLOAD', 'DOWNLOAD', 'GENERATE', 'EXPORT', 'SYNC', 'CONFIGURE', 'DENY', 'ALERT', 'ERROR', 'VERIFY'];

    /** event prefix (before the first dot) => module */
    private const MODULES = [
        'auth' => 'Authentication', 'two_factor' => 'Authentication', 'security' => 'Security',
        'user' => 'User Management', 'access' => 'Access Control',
        'case' => 'Case Management', 'report' => 'Intake and Reports Received', 'public_report' => 'Intake and Reports Received',
        'relationship' => 'Case Management', 'consent' => 'Case Management', 'case_note' => 'Progress Notes', 'monitoring_visit' => 'Progress Notes',
        'risk_assessment' => 'Safety and Risk', 'comprehensive_assessment' => 'Assessment', 'assessment' => 'Assessment',
        'child_participation' => 'Child Participation', 'case_plan' => 'Case Planning', 'case_aftercare' => 'Closure and Aftercare',
        'case_reintegration' => 'Closure and Aftercare', 'case_transfer' => 'Case Management',
        'service' => 'Services and Referrals', 'referral' => 'Services and Referrals', 'provider' => 'Services and Referrals',
        'conference' => 'Case Conference', 'mdt' => 'CPU Collaboration', 'cpu_chat' => 'Kalinga Connect',
        'document' => 'Documents', 'case_document' => 'Documents', 'mdt_attachment' => 'Documents',
        'case_report' => 'Reports', 'social_case_report' => 'Reports', 'management_report' => 'Reports', 'analytics' => 'Analytics',
        'task' => 'Calendar', 'calendar' => 'Calendar',
        'lgu' => 'LGU Registry', 'lgu_profile' => 'LGU Profile', 'lgu_cpu_member' => 'LGU Profile',
        'record' => 'Records Governance', 'record_hold' => 'Records Governance', 'record_disposition' => 'Records Governance',
        'identity_candidate' => 'Identity Review', 'identity_identifier' => 'Identity Review', 'identity_link' => 'Identity Review', 'identity_merge' => 'Identity Review',
        'form_field' => 'System Configuration', 'reference_data' => 'System Configuration', 'system' => 'System',
        'offline' => 'Offline and Sync', 'demo' => 'System', 'intake_draft' => 'Intake', 'case_remark' => 'Case Monitoring', 'kavat' => 'KaVAT', 'case_form' => 'Kalinga Forms', 'grant' => 'Grants',
    ];

    /** Exact events: [type, action, description] (module from the prefix). */
    private const EVENTS = [
        'auth.login' => ['authentication', 'LOGIN', 'Signed in'],
        'auth.logout' => ['authentication', 'LOGOUT', 'Signed out'],
        'auth.failed' => ['authentication', 'LOGIN', 'Sign-in failed'],
        'auth.google_login' => ['authentication', 'LOGIN', 'Signed in with Google'],
        'auth.google_rejected' => ['authentication', 'LOGIN', 'Google sign-in refused (no active account)'],
        'auth.lockout' => ['security', 'ALERT', 'Multiple failed sign-ins; sign-in temporarily blocked'],
        'auth.password_reset' => ['user_management', 'UPDATE', 'Password reset by the account owner'],
        'two_factor.verified' => ['authentication', 'VERIFY', 'Sign-in code verified'],
        'two_factor.locked' => ['security', 'ALERT', 'Too many wrong sign-in codes; verification locked'],
        'two_factor.totp_enabled' => ['user_management', 'UPDATE', 'Authenticator app enabled'],
        'two_factor.disabled' => ['user_management', 'UPDATE', 'Two-step verification disabled'],
        'two_factor.reset' => ['user_management', 'UPDATE', 'Two-step verification reset'],
        'security.repeated_access_denied' => ['security', 'ALERT', 'Repeated attempts to open pages without permission'],
        'access.denied' => ['access_permission', 'DENY', 'Access denied'],
        'user.self_registered' => ['user_management', 'CREATE', 'Account registered (pending approval)'],
        'user.created' => ['user_management', 'CREATE', 'Account created'],
        'user.registration_approved' => ['user_management', 'APPROVE', 'Account registration approved'],
        'user.registration_declined' => ['user_management', 'REJECT', 'Account registration declined'],
        'user.activated' => ['user_management', 'UPDATE', 'Account activated'],
        'user.deactivated' => ['user_management', 'UPDATE', 'Account deactivated'],
        'user.access_updated' => ['user_management', 'UPDATE', 'Account access updated'],
        'user.role_assigned' => ['access_permission', 'ASSIGN', 'Role assigned'],
        'user.scope_updated' => ['access_permission', 'UPDATE', 'Area of responsibility updated'],
        'user.lgu_assigned' => ['access_permission', 'ASSIGN', 'LGU assignment given'],
        'user.deleted' => ['user_management', 'DELETE', 'Account removed'],
        'user.self_deleted' => ['user_management', 'DELETE', 'Account removed by the account owner'],
        'case.viewed' => ['data_access', 'VIEW', 'Viewed case details'],
        'case.created' => ['data_creation', 'CREATE', 'Case folder created'],
        'case.updated' => ['data_modification', 'UPDATE', 'Case information edited'],
        'case.transitioned' => ['workflow_status', 'UPDATE', 'Case status changed'],
        'case.reassigned' => ['workflow_status', 'ASSIGN', 'Case reassigned to another case manager'],
        'case.archived' => ['data_deletion', 'ARCHIVE', 'Case archived'],
        'case.closure_recorded' => ['workflow_status', 'CLOSE', 'Case closure recorded'],
        'case.closure_reviewed' => ['workflow_status', 'APPROVE', 'Case closure reviewed'],
        'case.closure_history_captured' => ['workflow_status', 'CLOSE', 'Closure history captured'],
        'report.case_created' => ['workflow_status', 'SUBMIT', 'Report accepted and case opened'],
        'report.transitioned' => ['workflow_status', 'UPDATE', 'Received report status changed'],
        'public_report.submitted' => ['data_creation', 'SUBMIT', 'Public report submitted'],
        'referral.created' => ['workflow_status', 'REFER', 'Referral made'],
        'referral.transitioned' => ['workflow_status', 'UPDATE', 'Referral status changed'],
        'conference.finalized' => ['workflow_status', 'APPROVE', 'Case conference finalized'],
        'conference.returned_for_revision' => ['workflow_status', 'REJECT', 'Case conference returned for revision'],
        'case_note.finalized' => ['workflow_status', 'SUBMIT', 'Progress note finalized'],
        'case_note.addendum_finalized' => ['workflow_status', 'SUBMIT', 'Progress note addendum finalized'],
        'document.uploaded_quarantined' => ['document_file', 'UPLOAD', 'Document uploaded (held for scanning)'],
        'document.downloaded' => ['document_file', 'DOWNLOAD', 'Document downloaded'],
        'document.upload_failed' => ['system_error', 'ERROR', 'File upload failed'],
        'mdt.attachment_uploaded_quarantined' => ['document_file', 'UPLOAD', 'CPU attachment uploaded (held for scanning)'],
        'mdt.attachment_downloaded' => ['document_file', 'DOWNLOAD', 'CPU attachment downloaded'],
        'mdt.access_granted' => ['access_permission', 'ASSIGN', 'CPU member given access to a case'],
        'mdt.access_revoked' => ['access_permission', 'DELETE', 'CPU member access to a case removed'],
        'lgu_profile.logo_uploaded' => ['system_configuration', 'UPLOAD', 'LGU logo uploaded'],
        'lgu_profile.logo_removed' => ['system_configuration', 'DELETE', 'LGU logo removed'],
        'lgu_profile.updated' => ['system_configuration', 'CONFIGURE', 'LGU profile and signatories updated'],
        'lgu.created' => ['system_configuration', 'CREATE', 'LGU added to the registry'],
        'lgu.removed' => ['system_configuration', 'DELETE', 'LGU removed from the registry'],
        'form_field.saved' => ['system_configuration', 'CONFIGURE', 'Form field saved'],
        'form_field.deleted' => ['system_configuration', 'DELETE', 'Form field removed'],
        'case_report.generated' => ['report_generation', 'GENERATE', 'Case report generated'],
        'social_case_report.generated' => ['report_generation', 'GENERATE', 'Social case management report generated'],
        'social_case_report.downloaded' => ['report_generation', 'EXPORT', 'Social case management report downloaded'],
        'management_report.exported' => ['report_generation', 'EXPORT', 'Management report exported'],
        'analytics.exported' => ['report_generation', 'EXPORT', 'Analytics exported'],
        'analytics.quarterly_exported' => ['report_generation', 'EXPORT', 'Quarterly report exported'],
        'audit.exported' => ['report_generation', 'EXPORT', 'Audit log exported'],
        'offline.sync' => ['integration_sync', 'SYNC', 'Offline change synchronized'],
        'offline.intake_synced' => ['integration_sync', 'SYNC', 'Offline intake synchronized'],
        'offline.case_intake_synced' => ['integration_sync', 'SYNC', 'Offline case intake synchronized'],
        'offline.sync_conflict' => ['integration_sync', 'SYNC', 'Offline change not synchronized (record changed meanwhile)'],
        'offline.device_revoked' => ['access_permission', 'DELETE', 'Offline device removed'],
        'offline.device_revoked_by_admin' => ['access_permission', 'DELETE', 'Offline device removed by an administrator'],
        'system.error' => ['system_error', 'ERROR', 'Application error'],
        'system.database_error' => ['system_error', 'ERROR', 'Database error'],
        'intake_draft.created' => ['data_creation', 'CREATE', 'Intake saved to continue later'],
        'intake_draft.discarded' => ['data_deletion', 'DELETE', 'Saved intake discarded'],
        'intake_draft.expired' => ['data_deletion', 'DELETE', 'Saved intakes deleted after the keeping period'],
        'intake_draft.completed' => ['workflow_status', 'SUBMIT', 'Saved intake completed; case folder created'],
        'case.risk_level_lowered' => ['data_modification', 'UPDATE', 'Initial risk level set lower than suggested'],
        'document.uploaded' => ['document_file', 'UPLOAD', 'Document uploaded'],
        'kavat.created' => ['data_creation', 'CREATE', 'KaVAT started'],
        'kavat.finalized' => ['workflow_status', 'SUBMIT', 'KaVAT finalized'],
        'kavat.discarded' => ['data_deletion', 'DELETE', 'Unfinished KaVAT discarded'],
        'case_form.updated' => ['data_modification', 'UPDATE', 'Kalinga form edited'],
        'case_form.finalized' => ['workflow_status', 'SUBMIT', 'Kalinga form finalized'],
        'grant.created' => ['data_creation', 'CREATE', 'Grant recorded'],
        'grant.voided' => ['data_modification', 'UPDATE', 'Grant entry voided'],
        'case.monitored' => ['data_access', 'VIEW', 'Viewed the case monitoring summary'],
        'case_remark.created' => ['data_creation', 'CREATE', 'Remark added'],
        'case_remark.acknowledged' => ['workflow_status', 'UPDATE', 'Remark marked as read'],
        'demo.case_seeded' => ['data_creation', 'CREATE', 'Fictional demo case loaded'],
        'identity_merge.executed' => ['data_modification', 'UPDATE', 'Duplicate client records merged'],
        'identity_merge.approved' => ['workflow_status', 'APPROVE', 'Client record merge approved'],
        'identity_merge.proposed' => ['workflow_status', 'SUBMIT', 'Client record merge proposed'],
        'identity_candidate.viewed' => ['data_access', 'VIEW', 'Viewed possible duplicate client'],
        'record.disclosed' => ['data_access', 'VIEW', 'Record disclosure logged'],
    ];

    /** Last word of the event => [type, action]. */
    private const VERBS = [
        'created' => ['data_creation', 'CREATE'], 'added' => ['data_creation', 'CREATE'], 'recorded' => ['data_creation', 'CREATE'],
        'posted' => ['data_creation', 'CREATE'], 'captured' => ['data_creation', 'CREATE'], 'saved' => ['data_modification', 'UPDATE'],
        'section_saved' => ['data_modification', 'UPDATE'], 'updated' => ['data_modification', 'UPDATE'], 'configured' => ['data_modification', 'UPDATE'],
        'intervention_updated' => ['data_modification', 'UPDATE'], 'rescheduled' => ['workflow_status', 'UPDATE'], 'cancelled' => ['workflow_status', 'UPDATE'],
        'deleted' => ['data_deletion', 'DELETE'], 'removed' => ['data_deletion', 'DELETE'], 'revoked' => ['data_deletion', 'DELETE'], 'archived' => ['data_deletion', 'ARCHIVE'],
        'viewed' => ['data_access', 'VIEW'],
        'transitioned' => ['workflow_status', 'UPDATE'], 'status_changed' => ['workflow_status', 'UPDATE'], 'finalized' => ['workflow_status', 'SUBMIT'],
        'submitted' => ['workflow_status', 'SUBMIT'], 'reviewed' => ['workflow_status', 'APPROVE'], 'approved' => ['workflow_status', 'APPROVE'],
        'activated' => ['workflow_status', 'APPROVE'], 'review_finalized' => ['workflow_status', 'APPROVE'], 'resolved' => ['workflow_status', 'UPDATE'],
        'acknowledged' => ['workflow_status', 'UPDATE'], 'assigned' => ['workflow_status', 'ASSIGN'], 'action_assigned' => ['workflow_status', 'ASSIGN'],
        'proposed' => ['workflow_status', 'SUBMIT'], 'placed' => ['workflow_status', 'UPDATE'], 'released' => ['workflow_status', 'UPDATE'],
        'classified' => ['data_modification', 'UPDATE'], 'returned_for_revision' => ['workflow_status', 'REJECT'],
        'downloaded' => ['document_file', 'DOWNLOAD'], 'uploaded' => ['document_file', 'UPLOAD'], 'uploaded_quarantined' => ['document_file', 'UPLOAD'],
        'scan_completed' => ['document_file', 'UPDATE'],
        'generated' => ['report_generation', 'GENERATE'], 'exported' => ['report_generation', 'EXPORT'],
        'synced' => ['integration_sync', 'SYNC'], 'sync' => ['integration_sync', 'SYNC'],
    ];

    /** @return array{type: string, module: string, action: string, description: string} */
    public static function classify(string $event): array
    {
        $prefix = str_contains($event, '.') ? substr($event, 0, strpos($event, '.')) : $event;
        $verb = str_contains($event, '.') ? substr($event, strpos($event, '.') + 1) : $event;
        $module = self::MODULES[$prefix] ?? 'System';

        if (isset(self::EVENTS[$event])) {
            [$type, $action, $description] = self::EVENTS[$event];

            return compact('type', 'module', 'action', 'description');
        }

        $last = str_contains($verb, '.') ? substr($verb, strrpos($verb, '.') + 1) : $verb;
        [$type, $action] = self::VERBS[$verb] ?? self::VERBS[$last] ?? self::guess($last);
        if (in_array($module, ['System Configuration', 'LGU Registry', 'LGU Profile'], true) && in_array($type, ['data_creation', 'data_modification'], true)) {
            [$type, $action] = ['system_configuration', 'CONFIGURE'];
        }
        $description = ucfirst(str_replace('_', ' ', $prefix)) . ' ' . str_replace(['_', '.'], ' ', $verb);

        return compact('type', 'module', 'action', 'description');
    }

    /** @return array{0: string, 1: string} */
    private static function guess(string $last): array
    {
        foreach (self::VERBS as $suffix => $pair) {
            if (str_ends_with($last, $suffix)) {
                return $pair;
            }
        }

        return ['data_modification', 'UPDATE'];
    }

    /** Every module name, for the filter list. */
    public static function modules(): array
    {
        $modules = array_values(array_unique(self::MODULES));
        sort($modules);

        return $modules;
    }

    public static function logId(int $id): string
    {
        return 'AUD-' . str_pad((string) $id, 6, '0', STR_PAD_LEFT);
    }
}
