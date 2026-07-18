<?php

namespace App\Observers;

use App\Models\User;
use App\Services\FirmMembershipService;

class UserObserver
{
    public function __construct(private readonly FirmMembershipService $memberships) {}

    public function created(User $user): void
    {
        $this->memberships->ensureForCurrentFirm($user);
    }
}
