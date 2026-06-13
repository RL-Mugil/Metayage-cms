<?php

namespace App\Policies;

use App\Models\Invoice;
use App\Models\User;

class InvoicePolicy
{
    public function viewAny(User $user): bool
    {
        return in_array($user->role, ['super_admin', 'partner', 'finance', 'manager', 'client']);
    }

    public function view(User $user, Invoice $invoice): bool
    {
        if ($user->role === 'client') {
            return $invoice->client->contacts()->where('email', $user->email)->exists();
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
