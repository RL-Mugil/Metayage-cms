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

        // Any internal staff member may view any project.
        return true;
    }

    public function create(User $user): bool
    {
        // Any internal staff member may create projects; clients cannot.
        return ! $user->isClientRole();
    }

    public function update(User $user, Project $project): bool
    {
        if ($user->isClientRole()) {
            return false;
        }

        // Galvanizers remain scoped to their assigned circle (data-partition role).
        if ($user->isGalvanizer()) {
            return $user->canAccessCircle($project->circle);
        }

        // Any other internal staff member may edit any project — status, workflow
        // stage and fields. (Not limited to the assigned case manager.)
        return true;
    }

    public function delete(User $user, Project $project): bool
    {
        if ($user->isGalvanizer()) {
            return $user->canAccessCircle($project->circle);
        }

        // Deletion stays restricted to firm leadership.
        return in_array($user->role, ['super_admin', 'partner']);
    }
}
