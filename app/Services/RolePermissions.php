<?php

namespace App\Services;

use App\Models\User;

/**
 * Canonical RBAC catalogue aligned to CSPC Protocol 2025.
 * Controllers and UI consume permission names; roles are defined only here.
 */
class RolePermissions
{
    public const ROLES = [
        'system_admin' => ['System Administrator', 'IT administration only: accounts, LGU registry, form builder, audit logs and system settings. No case records.'],
        'project_manager' => ['Project Manager', 'Kalinga project team (Central Office / STB): monitors the project in all regions or in assigned regions, approves accounts, manages the LGU registry and office profiles. Sees cases with secured names only.'],
        'fo_user' => ['Field Office User', 'Field Office Social Technology Unit: monitors the project in its own region. Sees cases with secured names only and can add remarks.'],
        'lgu_head' => ['LGU Head / Supervisor', 'Monitors the CEFMU cases of the case managers in the LGU, adds remarks and approves case closure. Holds no cases.'],
        'social_worker' => ['Case Manager', 'Owns assigned CEFMU cases: intake, KaVAT, Kalinga forms, progress notes and referrals, closure and aftercare.'],
        'cpu_member' => ['CPU Member', 'Multidisciplinary team member with discipline-specific access; participates in case conferences and CPU coordination.'],
    ];

    /** Roles that monitor cases (secured names, read-only, remarks) instead of holding them. */
    public const MONITORING_ROLES = ['project_manager', 'fo_user', 'lgu_head'];

    public const CPU_DISCIPLINES = [
        'lea' => 'Law Enforcement Agent (LEA)',
        'lawyer' => 'Lawyer',
        'medical_doctor' => 'Medical Doctor',
        'psychiatrist' => 'Psychiatrist',
        'psychologist' => 'Psychologist',
        'barangay_official' => 'Barangay Official',
        'health_officer' => 'City/Municipal Health Officer',
        'social_worker_cpo' => 'City/Municipal Social Worker / CPO',
    ];

    public const PERMISSIONS = [
        'reports.create', 'reports.view', 'reports.screen', 'reports.validate', 'reports.accept', 'reports.reject',
        'risk.assess', 'assessments.manage', 'assessments.review', 'assessments.restricted_view', 'participation.manage', 'participation.review', 'participation.restricted_view', 'cases.view', 'cases.create', 'cases.update', 'cases.archive', 'cases.close', 'cases.reopen', 'cases.note',
        'relationships.manage', 'consents.record', 'plans.manage', 'plans.review', 'transfers.manage',
        'referrals.view', 'referrals.create', 'referrals.respond', 'referrals.close',
        'documents.upload', 'documents.download', 'documents.delete',
        'records.classify', 'records.restricted_view', 'records.retention.review', 'records.hold.manage', 'records.disposition.propose', 'records.disposition.review', 'records.disposition.approve', 'records.disclosure.record',
        'activities.manage', 'analytics.view', 'analytics.export', 'analytics.quarterly',
        'offline.use', 'admin.manage', 'audits.view', 'retention.manage', 'management.dashboard.view', 'management.report.export', 'reports.social.generate',
        'conferences.manage', 'conferences.review',
        'mdt.assign', 'mdt.manage', 'mdt.access', 'mdt.note.create', 'mdt.discussion.create',
        'cpu.chat.view', 'cpu.chat.post', 'cpu.chat.search', 'cpu.chat.capture', 'cpu.chat.manage',
        'lgu.profile.view', 'lgu.profile.manage',
        'cases.monitor', 'cases.remark', 'closure.approve', 'users.approve', 'lgu.registry.view', 'lgu.registry.manage',
        'identity.candidates.view', 'identity.review', 'identity.link', 'identity.merge.approve', 'identity.merge.execute', 'identity.merge.history.view', 'assessments.legacy.reconcile',
        // Phase 7: form options and user feedback; Phase 8: Connect across regions.
        'options.manage', 'feedback.review', 'connect.network',
    ];

    private const MONITOR = ['cases.monitor', 'cases.remark', 'analytics.view', 'analytics.export', 'analytics.quarterly', 'management.dashboard.view', 'management.report.export',
        'activities.manage', 'cpu.chat.view', 'cpu.chat.post', 'cpu.chat.search'];

    private const GRANTS = [
        'system_admin' => ['admin.manage', 'audits.view', 'retention.manage', 'users.approve', 'lgu.registry.view', 'lgu.registry.manage',
            'analytics.view', 'analytics.export', 'analytics.quarterly', 'management.dashboard.view', 'management.report.export', 'cpu.chat.view', 'cpu.chat.manage',
            'options.manage', 'feedback.review'],
        'project_manager' => [...self::MONITOR, 'users.approve', 'lgu.registry.view', 'lgu.registry.manage', 'lgu.profile.view', 'lgu.profile.manage',
            'options.manage', 'feedback.review', 'connect.network'],
        'fo_user' => [...self::MONITOR, 'lgu.registry.view', 'lgu.profile.view'],
        // The LGU Head supervises: reads the LGU's cases, approves closure and the
        // other second-person reviews, reassigns cases; never writes case content.
        'lgu_head' => [...self::MONITOR, 'closure.approve', 'cases.view', 'cases.close', 'documents.download', 'reports.social.generate',
            'assessments.review', 'plans.review', 'participation.review', 'conferences.review', 'transfers.manage',
            'assessments.restricted_view', 'participation.restricted_view',
            'identity.candidates.view', 'identity.review', 'identity.link', 'identity.merge.approve', 'identity.merge.execute', 'identity.merge.history.view',
            'records.classify', 'records.restricted_view', 'records.retention.review', 'records.hold.manage', 'records.disposition.propose', 'records.disposition.review', 'records.disposition.approve', 'records.disclosure.record'],
        'social_worker' => [
            'reports.create', 'reports.view', 'reports.screen', 'reports.validate', 'reports.accept', 'reports.reject',
            'risk.assess', 'cases.view', 'cases.create', 'cases.update', 'cases.archive', 'cases.close', 'cases.reopen', 'cases.note', 'cases.remark',
            'relationships.manage', 'consents.record', 'plans.manage',
            'referrals.view', 'referrals.create', 'referrals.respond', 'referrals.close',
            'documents.upload', 'documents.download', 'documents.delete',
            'activities.manage', 'analytics.view', 'analytics.export', 'analytics.quarterly',
            'offline.use', 'mdt.assign', 'mdt.manage', 'reports.social.generate',
            'cpu.chat.view', 'cpu.chat.post', 'cpu.chat.search', 'cpu.chat.capture',
            'lgu.profile.view', 'lgu.profile.manage',
        ],
        'cpu_member' => [
            'mdt.access', 'mdt.note.create', 'mdt.discussion.create',
            'cpu.chat.view', 'cpu.chat.post', 'cpu.chat.search', 'cpu.chat.capture',
            'referrals.respond',
            // Calendar: to see and answer invitations to shared activities (Phase 5).
            'activities.manage',
        ],
    ];

    public function allows(User $user, string $permission): bool
    {
        if ($permission === 'assessments.manage' && $this->isCaseworkRole($user)) {
            return true;
        }
        if ($permission === 'assessments.review' && $user->role === 'social_worker') {
            return true;
        }
        if ($permission === 'assessments.restricted_view' && $this->isCaseworkRole($user)) {
            return true;
        }
        if ($permission === 'participation.manage' && $this->isCaseworkRole($user)) {
            return true;
        }
        if ($permission === 'participation.review' && $user->role === 'social_worker') {
            return true;
        }
        if ($permission === 'participation.restricted_view' && $this->isCaseworkRole($user)) {
            return true;
        }
        if ($permission === 'plans.review' && $user->role === 'social_worker') {
            return true;
        }
        if ($permission === 'transfers.manage' && $user->role === 'social_worker') {
            return true;
        }
        if ($permission === 'conferences.manage' && $this->isCaseworkRole($user)) {
            return true;
        }
        if ($permission === 'conferences.review' && $user->role === 'social_worker') {
            return true;
        }
        if ($permission === 'identity.candidates.view' && $this->isCaseworkRole($user)) {
            return true;
        }
        if (in_array($permission, ['identity.review', 'identity.link', 'identity.merge.approve', 'identity.merge.execute', 'identity.merge.history.view', 'assessments.legacy.reconcile'], true) && $user->role === 'social_worker') {
            return true;
        }
        if ($permission === 'records.classify' && $this->isCaseworkRole($user)) {
            return true;
        }
        if ($permission === 'records.restricted_view' && $user->role === 'social_worker') {
            return true;
        }
        if (in_array($permission, ['records.retention.review', 'records.hold.manage', 'records.disposition.propose', 'records.disposition.review', 'records.disposition.approve', 'records.disclosure.record'], true) && $user->role === 'social_worker') {
            return true;
        }

        return in_array($permission, self::GRANTS[$user->role] ?? [], true);
    }

    public function permissionsFor(User $user): array
    {
        return $this->rolePermissions($user->role);
    }

    public function rolePermissions(?string $role): array
    {
        if (!$role) {
            return [];
        }

        $permissions = self::GRANTS[$role] ?? [];
        if ($role === 'social_worker') {
            $permissions[] = 'assessments.manage';
            $permissions[] = 'assessments.restricted_view';
            $permissions[] = 'participation.manage';
            $permissions[] = 'participation.restricted_view';
            $permissions[] = 'conferences.manage';
            $permissions[] = 'assessments.review';
            $permissions[] = 'plans.review';
            $permissions[] = 'transfers.manage';
            $permissions[] = 'participation.review';
            $permissions[] = 'conferences.review';
            $permissions[] = 'identity.candidates.view';
            $permissions = array_merge($permissions, ['identity.review', 'identity.link', 'identity.merge.approve', 'identity.merge.execute', 'identity.merge.history.view', 'assessments.legacy.reconcile']);
            $permissions[] = 'records.classify';
            $permissions[] = 'records.restricted_view';
            $permissions = array_merge($permissions, ['records.retention.review', 'records.hold.manage', 'records.disposition.propose', 'records.disposition.review', 'records.disposition.approve', 'records.disclosure.record']);
        }

        return array_values(array_unique($permissions));
    }

    /** The case manager role: owns and writes case content. */
    public function isCaseworkRole(User $user): bool
    {
        return $user->role === 'social_worker' && in_array('cases.view', self::GRANTS['social_worker'], true);
    }

    /** Display name of a role, for example "Case Manager". */
    public static function label(?string $role): string
    {
        return self::ROLES[$role][0] ?? ucwords(str_replace('_', ' ', (string) $role));
    }

    public function isMonitoringRole(User $user): bool
    {
        return in_array($user->role, self::MONITORING_ROLES, true);
    }

    public function isFieldWorker(User $user): bool
    {
        return $user->role === 'social_worker'
            && trim((string) $user->municipality) === '';
    }

    public function canViewAggregateOnly(User $user): bool
    {
        return $this->allows($user, 'analytics.view')
            && ($user->role === 'system_admin' || $this->isMonitoringRole($user));
    }
}
