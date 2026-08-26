<?php

namespace App\Policies;

use App\Models\Reminder;
use App\Models\User;

class ReminderPolicy
{
    public function viewAny(User $user): bool { return ! $user->isClientRole(); }

    public function view(User $user, Reminder $reminder): bool
    {
        return ! $user->isClientRole() && (
            (int) $reminder->user_id === (int) $user->id
            || (int) $reminder->assigned_user_id === (int) $user->id
            || ($reminder->scope === 'team' && in_array($user->role, ['super_admin', 'partner', 'manager'], true))
        );
    }

    public function create(User $user): bool { return ! $user->isClientRole(); }

    public function update(User $user, Reminder $reminder): bool
    {
        return $this->view($user, $reminder);
    }

    public function delete(User $user, Reminder $reminder): bool
    {
        return (int) $reminder->user_id === (int) $user->id
            || in_array($user->role, ['super_admin', 'partner'], true);
    }
}
