<?php

namespace App\Models;

use App\Casts\EncryptedSafe;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Client extends Model
{
    use SoftDeletes;

    protected $fillable = [
        // Core
        'client_code', 'client_type',
        // Identity
        'legal_name', 'company_name', 'trade_name',
        'entity_type', 'entity_subtype',
        'pan_number', 'cin_number', 'tax_id', 'website',
        // GST
        'has_gstin', 'gstin', 'gst_type',
        // Location
        'nationality', 'state', 'address',
        'primary_jurisdiction', 'secondary_jurisdictions',
        // Contact
        'contact_name', 'contact_email', 'phone',
        'language_preference',
        // Business
        'industry',
        'credit_limit', 'payment_terms', 'currency_preference',
        'billing_frequency', 'communication_preference',
        'account_manager_id', 'date_onboarded',
        // Banking
        'bank_name', 'bank_account', 'bank_ifsc',
        // Admin
        'referred_by_code', 'accounts_person', 'remarks',
        'status',
        // Portal
        'portal_enabled', 'portal_invited_at', 'portal_user_id',
    ];

    protected $casts = [
        'secondary_jurisdictions'  => 'array',
        'communication_preference' => 'array',
        'date_onboarded'           => 'date',
        'credit_limit'             => 'decimal:2',
        'has_gstin'                => 'boolean',
        'portal_enabled'           => 'boolean',
        'portal_invited_at'        => 'datetime',
        // Banking details encrypted at rest (tolerant of legacy plaintext)
        'bank_account'             => EncryptedSafe::class,
        'bank_ifsc'                => EncryptedSafe::class,
    ];

    public function contacts(): HasMany
    {
        return $this->hasMany(ClientContact::class);
    }

    public function scopeVisibleToUser(Builder $query, User $user): Builder
    {
        if (! $user->isClientRole()) {
            return $query;
        }

        return $query->where(function (Builder $scoped) use ($user) {
            $scoped->where('portal_user_id', $user->id)
                ->orWhereHas('contacts', fn (Builder $contactQuery) => $contactQuery->where('email', $user->email));
        });
    }

    public function isVisibleToUser(User $user): bool
    {
        if (! $user->isClientRole()) {
            return true;
        }

        if ((int) $this->portal_user_id === (int) $user->id) {
            return true;
        }

        return $this->contacts()->where('email', $user->email)->exists();
    }

    /** Resolve the Client a portal user belongs to. */
    public static function forUser(User $user): ?self
    {
        return self::visibleToUser($user)
            ->first();
    }

    public function portalUserIds(): array
    {
        $emails = $this->contacts()->pluck('email')->filter();

        return User::query()
            ->whereIn('role', User::CLIENT_ROLES)
            ->where(function (Builder $query) use ($emails) {
                if ($this->portal_user_id) {
                    $query->where('id', $this->portal_user_id);
                }

                if ($emails->isNotEmpty()) {
                    $method = $this->portal_user_id ? 'orWhereIn' : 'whereIn';
                    $query->{$method}('email', $emails);
                }
            })
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    public function accountManager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'account_manager_id');
    }

    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }
}
