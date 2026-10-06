<?php

namespace App\Policies;

use App\Models\User;

class ZohoQuoteExportPolicy
{
    public function create(User $user): bool
    {
        return in_array($user->role, ['super_admin', 'partner', 'finance'], true);
    }
}
