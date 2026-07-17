<?php

namespace App\Services;

use App\Models\FirmMembership;
use App\Models\User;
use App\Support\FirmContext;
use Illuminate\Support\Facades\DB;

class FirmMembershipService
{
    public function __construct(private readonly FirmContext $context) {}

    public function ensureForCurrentFirm(User $user, ?string $role = null): FirmMembership
    {
        $firmId = $this->context->idOrSingleActiveFirm();

        return DB::transaction(function () use ($firmId, $role, $user): FirmMembership {
            $lockedUser = User::query()->lockForUpdate()->findOrFail($user->id);
            $membership = FirmMembership::query()->firstOrNew([
                'firm_id' => $firmId,
                'user_id' => $lockedUser->id,
            ]);

            $membership->fill([
                'role' => $role ?? $lockedUser->role,
                'status' => $lockedUser->status === 'Active' ? 'Active' : 'Inactive',
                'is_default' => $membership->exists
                    ? $membership->is_default
                    : $lockedUser->current_firm_id === null,
                'joined_at' => $membership->joined_at ?? now(),
            ]);
            $membership->save();

            if ($lockedUser->current_firm_id === null) {
                $lockedUser->forceFill(['current_firm_id' => $firmId])->saveQuietly();
                $user->setAttribute('current_firm_id', $firmId);
            }

            return $membership;
        });
    }
}
