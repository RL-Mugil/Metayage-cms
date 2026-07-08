<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\User;

class ProjectPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Project $project): bool
    {
        if ($user->isClientRole()) {
            return $project->client && $project->client->isVisibleToUser($user);
        }

        // Associates and paralegals may only view projects they are directly assigned to.
        // Mirrors the scope in ProjectController::index() exactly to prevent
        // policy/controller divergence causing spurious 403s.
        if ($user->role === 'associate') {
            // Patent Analysts: only cases where they are PR, CM or SCM.
            if ($project->patent_engineer_id === $user->id) return true;
            if ($project->assigned_manager_id === $user->id) return true;
            if ($project->secondary_manager_id === $user->id) return true;
            return false;
        }

        return true;
    }

    public function create(User $user): bool
    {
        return in_array($user->role, ['super_admin', 'partner', 'manager']);
    }

    public function update(User $user, Project $project): bool
    {
        if (in_array($user->role, ['super_admin', 'partner'])) return true;
        if ($user->role === 'manager' && $project->assigned_manager_id === $user->id) return true;
        return false;
    }

    public function delete(User $user, Project $project): bool
    {
        return in_array($user->role, ['super_admin', 'partner']);
    }
}
