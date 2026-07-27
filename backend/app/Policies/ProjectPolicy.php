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

        if ($user->isGalvanizer()) {
            return $user->canAccessCircle($project->circle);
        }

        // Associates and paralegals may only view projects they are directly assigned to.
        // Mirrors the scope in ProjectController::index() exactly to prevent
        // policy/controller divergence causing spurious 403s.
        if ($user->role === 'associate') {
            // Patent Analysts: cases where they are PR, CM or SCM,
            // or have a task assigned on the case.
            if ($project->patent_engineer_id === $user->id) return true;
            if ($project->assigned_manager_id === $user->id) return true;
            if ($project->secondary_manager_id === $user->id) return true;
            if ($project->tasks()->where('assignee_id', $user->id)->exists()) return true;
            return false;
        }

        return true;
    }

    public function create(User $user): bool
    {
        return in_array($user->role, ['super_admin', 'partner', 'manager', 'galvanizer']);
    }

    public function update(User $user, Project $project): bool
    {
        if (in_array($user->role, ['super_admin', 'partner'])) return true;
        if ($user->isGalvanizer()) return $user->canAccessCircle($project->circle);
        if ($user->role === 'manager' && $project->assigned_manager_id === $user->id) return true;
        return false;
    }

    public function delete(User $user, Project $project): bool
    {
        if ($user->isGalvanizer()) return $user->canAccessCircle($project->circle);
        return in_array($user->role, ['super_admin', 'partner']);
    }
}
