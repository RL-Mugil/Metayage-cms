<?php

namespace App\Support;

use App\Models\ClientDesignation;
use App\Models\User;

/**
 * What a client's portal login may use, set by its designation's access checkboxes
 * (Manager, CFO, CTO, Accounts Person, Inventor, Other, or a designation the client or the
 * firm creates). A login without a designation falls back to its role's old fixed access.
 */
class ClientAccess
{
    public const PERMISSIONS = [
        'cases' => 'Cases & portfolio',
        'tasks' => 'Tasks, Kanban & calendar',
        'documents' => 'Documents',
        'discussions' => 'Discussions & messages',
        'approvals' => 'Approve quotes & approvals',
        'renewals' => 'Renewal decisions',
        'billing' => 'Invoices, payments & statement',
        'reports' => 'Reports',
        'ai' => 'AI Assistant',
        'claude_api' => 'Claude, API & integrations',
        'manage_users' => 'Manage users & designations',
        'audit_log' => 'Audit log',
    ];

    /** The ready-made designations (editable copies can be made per client). */
    public const PRESETS = [
        'Manager' => ['cases', 'tasks', 'documents', 'discussions', 'approvals', 'renewals', 'billing', 'reports', 'ai', 'claude_api', 'manage_users', 'audit_log'],
        'CFO' => ['billing', 'reports', 'documents', 'discussions', 'approvals', 'renewals'],
        'Accounts Person' => ['billing', 'reports', 'documents', 'discussions', 'approvals'],
        'CTO' => ['cases', 'tasks', 'documents', 'discussions', 'approvals'],
        'Inventor' => ['cases', 'approvals'],
        'Other' => ['cases', 'tasks', 'documents', 'discussions', 'billing'],
    ];

    /** The access a login had before designations (by role). */
    private const ROLE_DEFAULT = [
        'client_admin' => 'Manager',
        'client_finance' => 'Accounts Person',
        'client' => 'Other',
        'inventor' => 'Inventor',
    ];

    /** @return array<int, string> */
    public static function permissions(User $user): array
    {
        if (! $user->isClientRole() && ! $user->isInventor()) {
            return array_keys(self::PERMISSIONS);
        }
        $designation = $user->client_designation_id ? ClientDesignation::find($user->client_designation_id) : null;
        $list = $designation ? (array) $designation->permissions : self::PRESETS[self::ROLE_DEFAULT[$user->role] ?? 'Other'];

        return array_values(array_intersect(array_keys(self::PERMISSIONS), $list));
    }

    public static function can(User $user, string $permission): bool
    {
        return in_array($permission, self::permissions($user), true);
    }

    /** The role that matches a set of permissions (roles still scope data; the boxes decide access). */
    public static function roleFor(array $permissions, bool $inventor = false): string
    {
        if ($inventor) {
            return 'inventor';
        }
        if (in_array('manage_users', $permissions, true)) {
            return 'client_admin';
        }

        return ! in_array('cases', $permissions, true) && in_array('billing', $permissions, true) ? 'client_finance' : 'client';
    }

    /** Clean a list of permissions from a form (known keys only). */
    public static function clean(array $permissions): array
    {
        return array_values(array_intersect(array_keys(self::PERMISSIONS), array_map('strval', $permissions)));
    }
}
