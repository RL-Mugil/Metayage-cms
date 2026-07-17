<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Firm extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name', 'legal_name', 'slug', 'status', 'country_code',
        'timezone', 'currency', 'data_region', 'settings',
    ];

    protected function casts(): array
    {
        return [
            'settings' => 'array',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'Active');
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(FirmMembership::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'firm_user')
            ->using(FirmMembership::class)
            ->withPivot(['role', 'status', 'is_default', 'joined_at'])
            ->withTimestamps();
    }
}
