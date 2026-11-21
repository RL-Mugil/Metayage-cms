<?php

namespace App\Policies;

use App\Models\Inventor;
use App\Models\User;

/**
 * Inventor records (names and IPO particulars) are managed by the firm staff who open
 * and work cases. Client-portal users, inventors themselves, HR and finance cannot.
 */
class InventorPolicy
{
    public const MANAGER_ROLES = ['super_admin', 'partner', 'director', 'manager', 'associate', 'paralegal', 'galvanizer'];

    public function viewAny(User $user): bool
    {
        return in_array($user->role, self::MANAGER_ROLES, true);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, Inventor $inventor): bool
    {
        return $this->viewAny($user);
    }

    /** Giving an inventor a portal login: firm leadership only. */
    public function grantPortalAccess(User $user, Inventor $inventor): bool
    {
        return in_array($user->role, ['super_admin', 'partner', 'director', 'manager'], true);
    }
}
