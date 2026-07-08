<?php

namespace App\Policies;

use App\Models\Client;
use App\Models\User;

class ClientPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Client $client): bool
    {
        if ($user->isClientRole()) {
            return $client->isVisibleToUser($user);
        }
        return true;
    }

    public function create(User $user): bool
    {
        return in_array($user->role, ['super_admin', 'partner', 'manager']);
    }

    public function update(User $user, Client $client): bool
    {
        return in_array($user->role, ['super_admin', 'partner', 'manager']);
    }

    public function delete(User $user, Client $client): bool
    {
        return in_array($user->role, ['super_admin', 'partner']);
    }
}
