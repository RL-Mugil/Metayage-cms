<?php

namespace App\Policies;

use App\Models\Employee;
use App\Models\User;

class EmployeePolicy
{
    public function viewAny(User $user): bool
    {
        // All authenticated staff can view the employee directory (HR Overview).
        // Sensitive fields (salary, bank, PII) are stripped in the controller for non-HR roles.
        return !in_array($user->role, ['client', 'client_admin']);
    }

    public function view(User $user, Employee $employee): bool
    {
        if (!in_array($user->role, ['client', 'client_admin'])) return true;
        return $employee->user_id === $user->id;
    }

    public function create(User $user): bool
    {
        return in_array($user->role, ['super_admin', 'hr']);
    }

    public function update(User $user, Employee $employee): bool
    {
        return in_array($user->role, ['super_admin', 'hr']);
    }

    public function delete(User $user, Employee $employee): bool
    {
        return $user->role === 'super_admin';
    }

    public function approveLeave(User $user): bool
    {
        return in_array($user->role, User::LEAVE_APPROVER_ROLES, true);
    }
}
