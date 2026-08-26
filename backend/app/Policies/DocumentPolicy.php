<?php

namespace App\Policies;

use App\Models\Client;
use App\Models\Document;
use App\Models\Project;
use App\Models\User;

class DocumentPolicy
{
    public function viewAny(User $user): bool
    {
        return in_array($user->role, [
            'super_admin', 'partner', 'manager', 'associate', 'paralegal',
            'finance', 'hr', 'galvanizer', 'client', 'client_admin', 'client_finance',
        ], true);
    }

    public function view(User $user, Document $document): bool
    {
        if ($user->isClientRole()) return $document->client?->isVisibleToUser($user) === true;
        if (in_array($user->role, ['super_admin', 'partner'], true)) return true;

        if ($user->isGalvanizer()) {
            return ($document->project && $user->canAccessCircle($document->project->circle))
                || ($document->client && $user->canAccessCircle($document->client->circle))
                || (! $document->project_id && ! $document->client_id);
        }

        if ($user->role === 'manager') {
            return ! $document->project_id || $document->project?->assigned_manager_id === $user->id;
        }

        if (in_array($user->role, ['associate', 'paralegal'], true)) {
            return $document->project
                ? $this->assignedToProject($user, $document->project)
                : ! $document->client_id && $document->uploaded_by_id === $user->id;
        }

        return in_array($user->role, ['finance', 'hr'], true)
            && ! $document->client_id
            && ! $document->project_id;
    }

    public function create(User $user): bool { return $this->viewAny($user); }

    public function attachToProject(User $user, Project $project): bool
    {
        return $user->can('view', $project);
    }

    public function attachToClient(User $user, Client $client): bool
    {
        if ($user->isClientRole()) return $client->isVisibleToUser($user);
        if (in_array($user->role, ['super_admin', 'partner'], true)) return true;
        if ($user->isGalvanizer()) return $user->canAccessCircle($client->circle);
        return $user->role === 'manager' && $client->account_manager_id === $user->id;
    }

    public function delete(User $user, Document $document): bool
    {
        if (in_array($user->role, ['super_admin', 'partner'], true)) return true;
        return $user->role === 'manager'
            && $document->project
            && $document->project->assigned_manager_id === $user->id;
    }

    private function assignedToProject(User $user, Project $project): bool
    {
        return $project->patent_engineer_id === $user->id
            || $project->assigned_manager_id === $user->id
            || $project->secondary_manager_id === $user->id
            || $project->tasks()->where('assignee_id', $user->id)->exists();
    }
}
