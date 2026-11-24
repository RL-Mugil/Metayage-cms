<?php

namespace App\Policies;

use App\Models\ComplianceItem;
use App\Models\User;

class ComplianceItemPolicy
{
    public function viewAny(User $user): bool
    {
        // All firm staff: everyone sees the deadlines of the cases they may see (ComplianceController).
        return in_array($user->role, User::FIRM_STAFF_ROLES, true);
    }

    public function view(User $user, ComplianceItem $item): bool
    {
        if (\App\Support\DataAccess::seesAllCases($user)) {
            return true;
        }
        if ((int) $item->assignee_id === (int) $user->id) {
            return true;
        }
        if ($user->isGalvanizer()) {
            return $item->project && $user->canAccessCircle($item->project->circle);
        }

        // Patent Analysts / Engineers / Attorneys: items of their CM / SCM cases.
        return $item->project && in_array((int) $user->id, [(int) $item->project->assigned_manager_id, (int) $item->project->secondary_manager_id], true);
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
