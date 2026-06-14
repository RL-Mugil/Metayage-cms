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
        if ($user->role === 'client') {
            return $project->client && $project->client->contacts()->where('email', $user->email)->exists();
        }

        // Associates and paralegals may only view projects they are directly assigned to.
        if (in_array($user->role, ['associate', 'paralegal'])) {
            return $project->assigned_partner_id === $user->id
                || $project->assigned_manager_id === $user->id
                || $project->patent_engineer_id === $user->id
                || $project->tasks()->where('assignee_id', $user->id)->exists();
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
        if ($user->role === 'manager' && $project->manager_id === $user->id) return true;
        return false;
    }

    public function delete(User $user, Project $project): bool
    {
        return in_array($user->role, ['super_admin', 'partner']);
    }
}
