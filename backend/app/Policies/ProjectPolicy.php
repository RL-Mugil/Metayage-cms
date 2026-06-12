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
