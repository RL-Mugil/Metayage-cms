<?php

namespace App\Policies;

use App\Models\Invoice;
use App\Models\User;

class InvoicePolicy
{
    public function viewAny(User $user): bool
    {
        return in_array($user->role, ['super_admin', 'partner', 'finance', 'manager', 'client', 'client_admin']);
    }

    public function view(User $user, Invoice $invoice): bool
    {
        if ($user->isClientRole()) {
            return $invoice->client && $invoice->client->isVisibleToUser($user);
        }
        return in_array($user->role, ['super_admin', 'partner', 'finance', 'manager']);
    }

    public function create(User $user): bool
    {
        return in_array($user->role, ['super_admin', 'partner', 'finance', 'manager']);
    }

    public function update(User $user, Invoice $invoice): bool
    {
        return in_array($user->role, ['super_admin', 'partner', 'finance', 'manager']);
    }

    public function delete(User $user, Invoice $invoice): bool
    {
        return in_array($user->role, ['super_admin', 'partner']);
    }

    public function pay(User $user, Invoice $invoice): bool
    {
        return in_array($user->role, ['super_admin', 'partner', 'finance']);
    }
}
