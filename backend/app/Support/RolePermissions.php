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
        ];

        return $matrix[$role] ?? [];
    }
}
