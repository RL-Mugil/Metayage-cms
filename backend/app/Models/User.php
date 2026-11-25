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

#[Fillable(['name', 'email', 'password', 'role', 'status', 'avatar_url', 'timezone', 'language', 'notification_prefs', 'google_calendar_token', 'google_calendar_email', 'patent_agent_no', 'mobile'])]
#[Hidden(['password', 'remember_token', 'patent_agent_signature_path'])]
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
            'mobile'             => \App\Casts\EncryptedSafe::class,
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

    /**
     * Roles that belong to the external client portal.
     * client_admin: full case + billing visibility, manages portal sub-users, resolves approvals.
     * client: same case visibility as client_admin, no sub-user/approval management.
     * client_finance: billing/ledger only — no case, approval, or deadline visibility.
     */
    public const CLIENT_ROLES = ['client', 'client_admin', 'client_finance'];
    public const STAFF_ROLES = ['super_admin', 'partner', 'manager', 'hr', 'finance', 'associate', 'paralegal', 'galvanizer'];
    public const LEAVE_APPROVER_ROLES = ['super_admin', 'partner', 'hr'];

    /** Firm staff who can be a client's Client Manager (never client or inventor logins). */
    public const FIRM_STAFF_ROLES = ['super_admin', 'partner', 'director', 'manager', 'hr', 'finance', 'associate', 'paralegal', 'galvanizer'];

    /**
     * Full access to salary figures, payslips, payroll runs, loans, tax
     * declarations, Form 16, and reimbursement approvals — "director"
     * (partner), "accountant" (finance), and system admin only. HR manages
     * employee records generally but does NOT see or edit payroll/salary
     * data; a regular employee never sees another employee's payroll data
     * at all (self-service endpoints are scoped to their own records).
     */
    public const PAYROLL_ACCESS_ROLES = ['super_admin', 'partner', 'finance'];

    /** User IDs to notify whenever an employee submits a payroll/accounts
     *  request (reimbursement, loan, Form 16, investment declaration). */
    public static function payrollAccessUserIds(): array
    {
        return static::whereIn('role', self::PAYROLL_ACCESS_ROLES)->pluck('id')->all();
    }

    public function isClientRole(): bool
    {
        return in_array($this->role, self::CLIENT_ROLES, true);
    }

    public function isInventor(): bool
    {
        return $this->role === 'inventor';
    }

    /** The inventor record this login gives portal access to (role `inventor`). */
    public function inventorRecord(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(Inventor::class);
    }

    /**
     * Cases this user is inventor-of-record on: their inventor record
     * (inventors.user_id) linked through project_inventors. Unlike every other
     * portal role, an inventor is not scoped to one Client — they can be
     * inventor across multiple clients' cases (e.g. an outside professor).
     */
    public function projectsAsInventor(): \Illuminate\Database\Eloquent\Builder
    {
        return Project::whereHas('inventors', fn ($q) => $q->where('inventors.user_id', $this->id));
    }

    /**
     * Staff who see only cases they created or are assigned to (Project::scopeAssignedTo):
     * Patent Analysts / Engineers (associate) and Patent Attorneys (manager). System Admin,
     * Director, Accountant, HR and Paralegal see every case — see App\Support\DataAccess.
     */
    public const CASE_SCOPED_ROLES = ['associate', 'manager'];

    /** Patent Analysts / Engineers and Patent Attorneys, unless System Admin has opened all cases (Settings > System > Open access). */
    public function seesOnlyOwnCases(): bool
    {
        return in_array($this->role, self::CASE_SCOPED_ROLES, true) && ! \App\Support\DataAccess::seesAllCases($this);
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
