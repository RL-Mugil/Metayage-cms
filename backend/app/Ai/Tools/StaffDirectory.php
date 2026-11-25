<?php

namespace App\Ai\Tools;

use App\Ai\Tool;
use App\Models\Employee;
use App\Models\User;

class StaffDirectory extends Tool
{
    private const ROLE_LABEL = [
        'super_admin' => 'System Admin', 'partner' => 'Director', 'director' => 'Director', 'manager' => 'Patent Attorney',
        'finance' => 'Accountant', 'hr' => 'HR', 'associate' => 'Patent Analyst / Engineer', 'paralegal' => 'Paralegal', 'galvanizer' => 'Galvanizer',
    ];

    public function name(): string { return 'staff_directory'; }

    public function title(): string { return 'Staff directory'; }

    public function description(): string
    {
        return 'Active firm staff: name, portal role, designation, department and work email. Use it to find who someone is or who to contact. No salary, phone or personal details.';
    }

    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => [
            'query' => ['type' => 'string', 'description' => 'Part of a name, designation or department.'],
            'limit' => $this->limitProp(),
        ]];
    }

    public function handle(User $user, array $args): array
    {
        $query = Employee::query()->with(['designation', 'department', 'user:id,name,email,role'])
            ->where(fn ($q) => $q->whereNull('employment_status')->orWhereIn('employment_status', ['Active', 'On Leave', 'Probation']));
        $rows = $query->orderBy('full_name')->get()->map(fn (Employee $e) => [
            'name' => $e->full_name ?: $e->user?->name,
            'role' => self::ROLE_LABEL[$e->user?->role ?? ''] ?? null,
            'designation' => $e->designation?->title ?? $e->designation?->name,
            'department' => $e->department?->name,
            'work_email' => $e->work_email ?: $e->user?->email,
            'has_portal_login' => $e->user !== null,
        ]);
        if ($q = $this->str($args, 'query')) {
            $needle = mb_strtolower($q);
            $rows = $rows->filter(fn ($r) => str_contains(mb_strtolower(implode(' ', array_filter([$r['name'], $r['designation'], $r['department'], $r['role']]))), $needle));
        }
        $total = $rows->count();

        return ['total' => $total, 'staff' => $rows->take($this->limit($args, 50))->values()->all()];
    }
}
