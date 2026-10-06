<?php

namespace App\Policies;

use App\Models\User;
use App\Models\ZohoInvoice;

class ZohoInvoicePolicy
{
    public function viewSyncStatus(User $user): bool
    {
        return in_array($user->role, ['super_admin', 'partner', 'finance', 'manager', 'galvanizer'], true);
    }
    public function syncAny(User $user): bool
    {
        return in_array($user->role, ['super_admin', 'partner', 'finance'], true);
    }

    public function refresh(User $user, ZohoInvoice $record): bool
    {
        if ($this->syncAny($user)) {
            return true;
        }
        if (! $record->project_id) {
            return false;
        }
        if ($user->role === 'manager') {
            return $record->project()->where('assigned_manager_id', $user->id)->exists();
        }
        if ($user->isGalvanizer()) {
            return $record->project()->where(fn ($q) => $user->applyProjectScope($q))->exists();
        }
        return false;
    }
}
