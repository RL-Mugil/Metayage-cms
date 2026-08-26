<?php

namespace App\Policies;

use App\Models\IpRecord;
use App\Models\User;

class IpRecordPolicy
{
    public function viewAny(User $user): bool { return $user->role !== 'client_finance'; }

    public function view(User $user, IpRecord $record): bool
    {
        if ($user->isClientRole()) return $record->client->isVisibleToUser($user);
        if ($user->isInventor()) return $record->projects()->whereHas('inventors', fn ($query) => $query->whereKey($user->id))->exists();
        return true;
    }

    public function create(User $user): bool
    {
        return in_array($user->role, ['super_admin', 'partner', 'manager', 'galvanizer'], true);
    }

    public function update(User $user, IpRecord $record): bool
    {
        return in_array($user->role, ['super_admin', 'partner', 'manager', 'galvanizer'], true);
    }
}
