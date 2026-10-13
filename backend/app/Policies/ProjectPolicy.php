<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\User;

class ProjectPolicy
{
    public function viewAny(User $user): bool
    {
        return in_array($user->role, [
            'super_admin', 'partner', 'director', 'manager', 'associate', 'paralegal',
            'finance', 'hr', 'galvanizer', 'client', 'client_admin', 'inventor',
        ], true);
    }

    public function view(User $user, Project $project): bool
    {
        if ($user->isClientRole()) {
            return $project->client && $project->client->isVisibleToUser($user);
        }

        if ($user->isGalvanizer()) {
            return $user->canAccessCircle($project->circle);
        }

        if ($user->isInventor()) {
            return $project->inventors()->where('users.id', $user->id)->exists();
        }

        // Patent Analysts, paralegals and HR see only cases they created or are
        // assigned to (PR, PCM, SCM, attorney, or a task). Mirrors
        // Project::scopeAssignedTo(), which scopes the list endpoints.
        if ($user->seesOnlyOwnCases()) {
            return $project->isAssignedTo($user->id);
        }

        return in_array($user->role, ['super_admin', 'partner', 'director', 'manager', 'finance'], true);
    }

    /** Any firm staff member may open a case; client-portal users and inventors may not. */
    public function create(User $user): bool
    {
        return in_array($user->role, [...User::STAFF_ROLES, 'director'], true);
    }

    public function update(User $user, Project $project): bool
    {
        if (in_array($user->role, ['super_admin', 'partner'])) return true;
        if ($user->isGalvanizer()) return $user->canAccessCircle($project->circle);
        if ($user->role === 'manager' && $project->assigned_manager_id === $user->id) return true;
        // Case workers (Patent Analysts, paralegals) edit and move the stages of cases
        // they created or are assigned to (PR, PCM, SCM, attorney, or a task).
        if (in_array($user->role, ['associate', 'paralegal'], true)) return $project->isAssignedTo($user->id);
        return false;
    }

    /** Status-only changes (e.g. Granted with its grant date) — anyone who may update the case. */
    public function changeStatus(User $user, Project $project): bool
    {
        return $this->update($user, $project);
    }

    public function delete(User $user, Project $project): bool
    {
        if ($user->isGalvanizer()) return $user->canAccessCircle($project->circle);
        return in_array($user->role, ['super_admin', 'partner']);
    }
}
