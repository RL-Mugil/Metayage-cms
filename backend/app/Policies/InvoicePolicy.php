<?php

namespace App\Policies;

use App\Models\Invoice;
use App\Models\Project;
use App\Models\User;

class InvoicePolicy
{
    public function viewAny(User $user): bool
    {
        return in_array($user->role, ['super_admin', 'partner', 'finance', 'manager', 'associate', 'galvanizer', 'client', 'client_admin']);
    }

    public function view(User $user, Invoice $invoice): bool
    {
        if ($user->isClientRole()) {
            return $invoice->client && $invoice->client->isVisibleToUser($user);
        }
        if (in_array($user->role, ['super_admin', 'partner', 'finance', 'manager'])) {
            return true;
        }

        if ($user->isGalvanizer()) {
            return $invoice->project && $user->canAccessCircle($invoice->project->circle);
        }

        if ($user->role === 'associate') {
            return $invoice->project && $this->canAccessProject($user, $invoice->project);
        }

        return false;
    }

    public function create(User $user): bool
    {
        return in_array($user->role, ['super_admin', 'partner', 'finance', 'manager', 'associate', 'galvanizer']);
    }

    public function update(User $user, Invoice $invoice): bool
    {
        if (in_array($user->role, ['super_admin', 'partner', 'finance', 'manager'])) {
            return true;
        }

        if ($user->isGalvanizer()) {
            return $invoice->project && $user->canAccessCircle($invoice->project->circle);
        }

        if ($user->role === 'associate') {
            return $invoice->status === 'Draft'
                && $invoice->project
                && $this->canAccessProject($user, $invoice->project);
        }

        return false;
    }

    public function delete(User $user, Invoice $invoice): bool
    {
        if ($user->isGalvanizer()) {
            return $invoice->project && $user->canAccessCircle($invoice->project->circle);
        }
        return in_array($user->role, ['super_admin', 'partner']);
    }

    public function pay(User $user, Invoice $invoice): bool
    {
        if ($user->isGalvanizer()) {
            return $invoice->project && $user->canAccessCircle($invoice->project->circle);
        }
        return in_array($user->role, ['super_admin', 'partner', 'finance']);
    }

    private function canAccessProject(User $user, Project $project): bool
    {
        if ($project->patent_engineer_id === $user->id) return true;
        if ($project->assigned_manager_id === $user->id) return true;
        if ($project->secondary_manager_id === $user->id) return true;

        return $project->tasks()->where('assignee_id', $user->id)->exists();
    }
}
