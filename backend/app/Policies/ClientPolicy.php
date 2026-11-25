<?php

namespace App\Policies;

use App\Models\Client;
use App\Models\User;
use App\Support\DataAccess;

class ClientPolicy
{
    public function viewAny(User $user): bool
    {
        // Inventors are external logins tied to cases, not to a client list.
        return ! $user->isInventor();
    }

    public function view(User $user, Client $client): bool
    {
        if ($user->isInventor()) {
            return false;
        }
        if ($user->isClientRole()) {
            return $client->isVisibleToUser($user);
        }
        if ($user->isGalvanizer()) {
            return $user->canAccessCircle($client->circle);
        }
        // Patent Analysts / Attorneys: their own clients (Client Manager, or PCM / SCM on a case),
        // or every client while System Admin has Open access > Clients on.
        if (in_array($user->role, User::CASE_SCOPED_ROLES, true) && ! DataAccess::seesAllClients($user)) {
            return DataAccess::ownsClient($user, $client);
        }
        return true;
    }

    public function create(User $user): bool
    {
        return in_array($user->role, ['super_admin', 'partner', 'manager', 'galvanizer']);
    }

    public function update(User $user, Client $client): bool
    {
        if ($user->isGalvanizer()) {
            return $user->canAccessCircle($client->circle);
        }
        if (in_array($user->role, ['super_admin', 'partner'], true)) {
            return true;
        }
        // While Open access > Clients is on, Patent Analysts and Attorneys edit any client,
        // e.g. to claim it by setting themselves as Client Manager.
        if (DataAccess::clientsOpenedBySwitch($user)) {
            return true;
        }
        // Patent Attorneys edit their own clients.
        return $user->role === 'manager' && DataAccess::ownsClient($user, $client);
    }

    public function delete(User $user, Client $client): bool
    {
        if ($user->isGalvanizer()) {
            return $user->canAccessCircle($client->circle);
        }
        return in_array($user->role, ['super_admin', 'partner']);
    }
}
