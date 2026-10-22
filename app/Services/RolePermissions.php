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
        'system_admin' => ['System Administrator', 'Accounts, configuration, audit and retention administration; no substantive casework access.'],
        'social_worker' => ['Social Worker / Case Manager', 'Owns assigned CEFMU casework: intake, assessment, planning, intervention, referral, monitoring, closure.'],
        'cpu_member' => ['CPU Member', 'Multidisciplinary team member with discipline-specific access; participates in case conferences and CPU coordination.'],
        'fo_user' => ['Field Office User', 'Regional casework oversight within assigned province.'],
    ];

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
        'risk.assess', 'assessments.manage', 'assessments.review', 'assessments.restricted_view', 'participation.manage', 'participation.review', 'participation.restricted_view', 'cases.view', 'cases.create', 'cases.update', 'cases.archive', 'cases.assign', 'cases.close', 'cases.reopen', 'cases.note',
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
        'identity.candidates.view', 'identity.review', 'identity.link', 'identity.merge.approve', 'identity.merge.execute', 'identity.merge.history.view', 'assessments.legacy.reconcile',
    ];

    private const GRANTS = [
        'system_admin' => ['lgu.profile.view', 'lgu.profile.manage', 'admin.manage', 'audits.view', 'retention.manage', 'analytics.view', 'analytics.export', 'analytics.quarterly', 'management.dashboard.view', 'management.report.export', 'cpu.chat.view', 'cpu.chat.manage'],
        'social_worker' => [
            'reports.create', 'reports.view', 'reports.screen', 'reports.validate', 'reports.accept', 'reports.reject',
            'risk.assess', 'cases.view', 'cases.create', 'cases.update', 'cases.archive', 'cases.assign', 'cases.close', 'cases.reopen', 'cases.note',
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
        ],
        'fo_user' => [
            'reports.create', 'reports.view', 'risk.assess',
            'cases.view', 'cases.update', 'cases.assign', 'cases.close', 'cases.reopen', 'cases.note',
            'referrals.view', 'referrals.respond',
            'documents.upload', 'documents.download',
            'activities.manage', 'analytics.view', 'analytics.export', 'analytics.quarterly',
            'mdt.assign', 'mdt.manage', 'lgu.profile.view',
        ],
    ];

    public function allows(User $user, string $permission): bool
    {
        if ($permission === 'assessments.manage' && $this->isCaseworkRole($user)) {
            return true;
        }
        if ($permission === 'assessments.review' && in_array($user->role, ['social_worker', 'fo_user'], true)) {
            return true;
        }
        if ($permission === 'assessments.restricted_view' && $this->isCaseworkRole($user)) {
            return true;
        }
        if ($permission === 'participation.manage' && $this->isCaseworkRole($user)) {
            return true;
        }
        if ($permission === 'participation.review' && in_array($user->role, ['social_worker', 'fo_user'], true)) {
            return true;
        }
        if ($permission === 'participation.restricted_view' && $this->isCaseworkRole($user)) {
            return true;
        }
        if ($permission === 'plans.review' && in_array($user->role, ['social_worker', 'fo_user'], true)) {
            return true;
        }
        if ($permission === 'transfers.manage' && in_array($user->role, ['social_worker', 'fo_user'], true)) {
            return true;
        }
        if ($permission === 'conferences.manage' && $this->isCaseworkRole($user)) {
            return true;
        }
        if ($permission === 'conferences.review' && in_array($user->role, ['social_worker', 'fo_user'], true)) {
            return true;
        }
        if ($permission === 'identity.candidates.view' && $this->isCaseworkRole($user)) {
            return true;
        }
        if (in_array($permission, ['identity.review', 'identity.link', 'identity.merge.approve', 'identity.merge.execute', 'identity.merge.history.view', 'assessments.legacy.reconcile'], true) && in_array($user->role, ['social_worker', 'fo_user'], true)) {
            return true;
        }
        if ($permission === 'records.classify' && $this->isCaseworkRole($user)) {
            return true;
        }
        if ($permission === 'records.restricted_view' && in_array($user->role, ['social_worker', 'fo_user'], true)) {
            return true;
        }
        if (in_array($permission, ['records.retention.review', 'records.hold.manage', 'records.disposition.propose', 'records.disposition.review', 'records.disposition.approve', 'records.disclosure.record'], true) && in_array($user->role, ['social_worker', 'fo_user'], true)) {
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
        if (in_array($role, ['social_worker', 'fo_user'], true)) {
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

    public function isCaseworkRole(User $user): bool
    {
        return $this->allows($user, 'cases.view');
    }

    public function isFieldWorker(User $user): bool
    {
        return $user->role === 'social_worker'
            && trim((string) $user->municipality) === '';
    }

    public function canViewAggregateOnly(User $user): bool
    {
        return $this->allows($user, 'analytics.view')
            && $user->role === 'system_admin';
    }
}
