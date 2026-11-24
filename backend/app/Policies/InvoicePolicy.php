<?php

namespace App\Policies;

use App\Models\Invoice;
use App\Models\Project;
use App\Models\User;

class InvoicePolicy
{
    public function viewAny(User $user): bool
    {
        // Clients see their own; staff by App\Support\DataAccess (all finance, circle, or CM / SCM cases).
        return $user->isClientRole() || \App\Support\DataAccess::canOpenFinance($user);
    }

    public function view(User $user, Invoice $invoice): bool
    {
        if ($user->isClientRole()) {
            return $invoice->client && $invoice->client->isVisibleToUser($user);
        }
        if (\App\Support\DataAccess::seesAllFinance($user)) {
            return true;
        }

        if ($user->isGalvanizer()) {
            return $invoice->project && $user->canAccessCircle($invoice->project->circle);
        }

        return \App\Support\DataAccess::canOpenFinance($user) && $invoice->project && $this->managesCase($user, $invoice->project);
    }

    /** Raising / changing invoices: finance roles, the Financial access list, galvanizers (their circle). */
    public function create(User $user): bool
    {
        return \App\Support\DataAccess::canActOnFinance($user) || $user->isGalvanizer();
    }

    public function update(User $user, Invoice $invoice): bool
    {
        if (\App\Support\DataAccess::canActOnFinance($user)) {
            return true;
        }

        if ($user->isGalvanizer()) {
            return $invoice->project && $user->canAccessCircle($invoice->project->circle);
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
        return \App\Support\DataAccess::canActOnFinance($user);
    }

    private function managesCase(User $user, Project $project): bool
    {
        return (int) $project->assigned_manager_id === (int) $user->id || (int) $project->secondary_manager_id === (int) $user->id;
    }

    private function canAccessProject(User $user, Project $project): bool
    {
        if ($project->patent_engineer_id === $user->id) return true;
        if ($project->assigned_manager_id === $user->id) return true;
        if ($project->secondary_manager_id === $user->id) return true;

        return $project->tasks()->where('assignee_id', $user->id)->exists();
    }
}
