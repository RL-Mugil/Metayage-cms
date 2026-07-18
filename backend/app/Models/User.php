<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'password', 'role', 'status', 'avatar_url', 'timezone', 'language', 'notification_prefs', 'google_calendar_token', 'google_calendar_email'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at'  => 'datetime',
            'password'           => 'hashed',
            'notification_prefs' => 'array',
            'current_firm_id'    => 'integer',
        ];
    }

    public function currentFirm(): BelongsTo
    {
        return $this->belongsTo(Firm::class, 'current_firm_id');
    }

    public function firmMemberships(): HasMany
    {
        return $this->hasMany(FirmMembership::class);
    }

    public function firms(): BelongsToMany
    {
        return $this->belongsToMany(Firm::class, 'firm_user')
            ->using(FirmMembership::class)
            ->withPivot(['role', 'status', 'is_default', 'joined_at'])
            ->withTimestamps();
    }

    /** Roles that belong to the external client portal. */
    public const CLIENT_ROLES = ['client', 'client_admin'];
    public const STAFF_ROLES = ['super_admin', 'partner', 'manager', 'hr', 'finance', 'associate', 'paralegal', 'galvanizer'];
    public const LEAVE_APPROVER_ROLES = ['super_admin', 'partner', 'hr'];

    public function isClientRole(): bool
    {
        return in_array($this->role, self::CLIENT_ROLES, true);
    }

    public function isGalvanizer(): bool
    {
        return $this->role === 'galvanizer';
    }

    public function galvanizerCircleSlugs(): array
    {
        if (! $this->isGalvanizer()) {
            return [];
        }

        return DB::table('tracker_circle_members as tcm')
            ->join('tracker_circles as tc', 'tc.id', '=', 'tcm.circle_id')
            ->where('tcm.user_id', $this->id)
            ->pluck('tc.slug')
            ->map(fn ($slug) => strtolower((string) $slug))
            ->unique()
            ->values()
            ->all();
    }

    public function galvanizerCircleCodes(): array
    {
        return array_map('strtoupper', $this->galvanizerCircleSlugs());
    }

    public function canAccessCircle(?string $circle): bool
    {
        if (! $this->isGalvanizer()) {
            return true;
        }

        if ($circle === null || $circle === '') {
            return false;
        }

        return in_array(strtoupper($circle), $this->galvanizerCircleCodes(), true);
    }

    public function defaultGalvanizerCircleCode(): ?string
    {
        return $this->galvanizerCircleCodes()[0] ?? null;
    }

    /**
     * Apply client visibility scope for galvanizer:
     * clients in their circle OR clients with no circle assignment.
     */
    public function applyClientScope($query): void
    {
        $codes = $this->galvanizerCircleCodes();
        $query->where(function ($q) use ($codes) {
            $q->whereIn('circle', $codes)
              ->orWhereNull('circle')
              ->orWhere('circle', '');
        });
    }

    /**
     * Apply project visibility scope for galvanizer:
     * projects in their circle OR projects where they are PCM/SCM/PR.
     */
    public function applyProjectScope($query): void
    {
        $codes = $this->galvanizerCircleCodes();
        $id    = $this->id;
        $query->where(function ($q) use ($codes, $id) {
            $q->whereIn('circle', $codes)
              ->orWhere('assigned_manager_id', $id)
              ->orWhere('secondary_manager_id', $id)
              ->orWhere('patent_engineer_id', $id);
        });
    }

    /**
     * Apply employee visibility scope for galvanizer:
     * employees whose user account is a member of the galvanizer's circle(s).
     * Applies to models with a direct `user_id` column (Employee, etc.).
     */
    public function applyEmployeeScope($query): void
    {
        $codes = $this->galvanizerCircleCodes();
        $circleUserIds = DB::table('tracker_circle_members as tcm')
            ->join('tracker_circles as tc', 'tc.id', '=', 'tcm.circle_id')
            ->whereIn('tc.code', $codes)
            ->pluck('tcm.user_id')
            ->all();
        $query->whereIn('user_id', $circleUserIds ?: [-1]);
    }
}
