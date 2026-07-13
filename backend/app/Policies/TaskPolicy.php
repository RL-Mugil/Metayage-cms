<?php

namespace App\Policies;

use App\Models\Task;
use App\Models\User;

class TaskPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Task $task): bool
    {
        if ($user->isGalvanizer()) {
            return $task->project && $user->canAccessCircle($task->project->circle);
        }
        return true;
    }

    public function create(User $user): bool
    {
        return in_array($user->role, ['super_admin', 'partner', 'manager', 'associate', 'galvanizer']);
    }

    public function update(User $user, Task $task): bool
    {
        if (in_array($user->role, ['super_admin', 'partner', 'manager'])) return true;
        if ($user->isGalvanizer()) return $task->project && $user->canAccessCircle($task->project->circle);
        if ($task->assignee_id === $user->id || $task->reviewer_id === $user->id) return true;
        return false;
    }

    public function delete(User $user, Task $task): bool
    {
        if ($user->isGalvanizer()) return $task->project && $user->canAccessCircle($task->project->circle);
        return in_array($user->role, ['super_admin', 'partner', 'manager']);
    }
}
