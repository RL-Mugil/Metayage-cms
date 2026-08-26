<?php

namespace App\Policies;

use App\Models\ComplianceItem;
use App\Models\User;

class ComplianceItemPolicy
{
    public function viewAny(User $user): bool
    {
        return in_array($user->role, ['super_admin', 'partner', 'director', 'manager', 'galvanizer'], true);
    }

    public function view(User $user, ComplianceItem $item): bool
    {
        if (in_array($user->role, ['super_admin', 'partner', 'director', 'manager'], true)) {
            return true;
        }

        return $user->isGalvanizer()
            && (($item->project && $user->canAccessCircle($item->project->circle))
                || (int) $item->assignee_id === (int) $user->id);
    }

    public function create(User $user): bool
    {
        return in_array($user->role, ['super_admin', 'partner', 'director', 'manager'], true);
    }

    public function update(User $user, ComplianceItem $item): bool
    {
        return $this->view($user, $item);
    }

    public function remind(User $user, ComplianceItem $item): bool
    {
        return $this->view($user, $item);
    }
}
