<?php

namespace App\Http\Middleware;

use App\Models\Firm;
use App\Models\FirmMembership;
use App\Models\User;
use App\Support\FirmContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class ResolveFirmContext
{
    public function __construct(private readonly FirmContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user) {
            return $next($request);
        }

        $firm = $this->resolveFirm($user);
        abort_unless($firm, 403, 'No active firm membership is available for this account.');

        $this->context->set($firm);
        $request->attributes->set('firm', $firm);
        $request->attributes->set('firm_id', $firm->id);

        try {
            return $next($request);
        } finally {
            $this->context->clear();
        }
    }

    private function resolveFirm(User $user): ?Firm
    {
        if ($user->current_firm_id) {
            $firm = $user->firms()
                ->whereKey($user->current_firm_id)
                ->where('firms.status', 'Active')
                ->wherePivot('status', 'Active')
                ->first();

            if ($firm) {
                return $firm;
            }
        }

        $firm = $user->firms()
            ->where('firms.status', 'Active')
            ->wherePivot('status', 'Active')
            ->orderByDesc('firm_user.is_default')
            ->first();

        if ($firm) {
            $user->forceFill(['current_firm_id' => $firm->id])->saveQuietly();

            return $firm;
        }

        return $this->attachSingleFirmCompatibilityMembership($user);
    }

    /**
     * Transitional support for legacy user creation paths. This is allowed
     * only while exactly one active firm exists, so it cannot cross tenants.
     */
    private function attachSingleFirmCompatibilityMembership(User $user): ?Firm
    {
        return DB::transaction(function () use ($user) {
            $firms = Firm::active()->lockForUpdate()->limit(2)->get();
            if ($firms->count() !== 1) {
                return null;
            }

            $firm = $firms->first();
            $lockedUser = User::query()->lockForUpdate()->findOrFail($user->id);

            FirmMembership::query()->firstOrCreate(
                ['firm_id' => $firm->id, 'user_id' => $lockedUser->id],
                [
                    'role' => $lockedUser->role,
                    'status' => $lockedUser->status === 'Active' ? 'Active' : 'Inactive',
                    'is_default' => true,
                    'joined_at' => now(),
                ],
            );

            if ($lockedUser->status !== 'Active') {
                return null;
            }

            $lockedUser->forceFill(['current_firm_id' => $firm->id])->saveQuietly();
            $user->setAttribute('current_firm_id', $firm->id);

            return $firm;
        });
    }
}
