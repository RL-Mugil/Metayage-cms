<?php

namespace App\Support;

final class RolePermissions
{
    public static function forRole(string $role): array
    {
        $matrix = [
            'super_admin' => ['all' => true],
            'partner' => [
                'clients' => 'view_edit',
                'projects' => 'view_edit',
                'kanban' => 'view_edit',
                'tasks' => 'view_edit',
                'documents' => 'view_edit',
                'approvals' => 'approve',
                'financial' => 'view_edit',
                'hrms' => 'view_edit',
                'reports' => 'view_edit',
                'ai' => 'view_edit',
            ],
            'director' => [
                'clients' => 'view_edit',
                'projects' => 'view_edit',
                'kanban' => 'view_edit',
                'tasks' => 'view_edit',
                'documents' => 'view_edit',
                'approvals' => 'approve',
                'financial' => 'view_edit',
                'hrms' => 'view_edit',
                'reports' => 'view_edit',
                'ai' => 'view_edit',
            ],
            'manager' => [
                'clients' => 'view_edit',
                'projects' => 'view_edit',
                'kanban' => 'view_edit',
                'tasks' => 'view_edit',
                'documents' => 'view_edit',
                'approvals' => 'approve',
                'financial' => 'view',
                'hrms' => 'department',
                'reports' => 'view',
                'ai' => 'view_edit',
            ],
            'associate' => [
                'clients' => 'view',
                'projects' => 'view',
                'kanban' => 'view_edit',
                'tasks' => 'view_edit',
                'documents' => 'view_edit',
                'approvals' => 'submit',
                'financial' => 'none',
                'hrms' => 'self_only',
                'reports' => 'none',
                'ai' => 'view_edit',
            ],
            'paralegal' => [
                'clients' => 'view',
                'projects' => 'view',
                'kanban' => 'view_edit',
                'tasks' => 'view_edit',
                'documents' => 'view_edit',
                'approvals' => 'submit',
                'financial' => 'none',
                'hrms' => 'self_only',
                'reports' => 'none',
                'ai' => 'view_edit',
            ],
            'finance' => [
                'clients' => 'view',
                'projects' => 'view',
                'financial' => 'view_edit',
                'hrms' => 'self_only',
                'reports' => 'view',
            ],
            'galvanizer' => [
                'clients' => 'circle_view_edit',
                'projects' => 'circle_view_edit',
                'kanban' => 'circle_view_edit',
                'tasks' => 'circle_view_edit',
                'documents' => 'circle_view_edit',
                'approvals' => 'view',
                'financial' => 'circle_view_edit',
                'hrms' => 'view',
                'leave' => 'notify_only',
                'payroll' => 'none',
                'recruitment' => 'none',
                'offboarding' => 'none',
                'reports' => 'circle_view',
                'ai' => 'view_edit',
            ],
            'hr' => [
                'hrms' => 'view_edit',
                'clients' => 'none',
                'projects' => 'none',
                'financial' => 'none',
            ],
            'client' => [
                'clients' => 'self_only',
                'projects' => 'self_only',
                'kanban' => 'self_only',
                'tasks' => 'self_only',
                'documents' => 'self_only',
                'financial' => 'self_only',
                'approvals' => 'view',
                'hrms' => 'none',
            ],
            'client_admin' => [
                'clients' => 'self_only',
                'projects' => 'self_only',
                'kanban' => 'self_only',
                'tasks' => 'self_only',
                'documents' => 'self_only',
                'financial' => 'self_only',
                'approvals' => 'approve',
                'portal_users' => 'view_edit',
                'hrms' => 'none',
            ],
            // Billing-only client login: sees financial/invoice data for its own client
            // and nothing else — no case visibility, no approvals, no portal user management.
            'client_finance' => [
                'clients' => 'none',
                'projects' => 'none',
                'kanban' => 'none',
                'tasks' => 'none',
                'documents' => 'self_only',
                'financial' => 'self_only',
                'approvals' => 'none',
                'portal_users' => 'none',
                'hrms' => 'none',
            ],
            // Inventor login: read-only, scoped to cases where they're inventor-of-record
            // (project_inventors), not to any one client — see ProjectPolicy::view().
            'inventor' => [
                'clients' => 'none',
                'projects' => 'self_only',
                'kanban' => 'none',
                'tasks' => 'none',
                'documents' => 'none',
                'financial' => 'none',
                'approvals' => 'none',
                'portal_users' => 'none',
                'hrms' => 'none',
            ],
        ];

        return $matrix[$role] ?? [];
    }
}
