<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ZohoOpeningBalance extends Model
{
    protected $fillable = [
        'scope', 'zoho_contact_id', 'client_id', 'customer_name', 'location_id',
        'balance_date', 'amount', 'balance', 'currency', 'source', 'synced_at',
    ];

    protected $casts = [
        'balance_date' => 'date',
        'amount' => 'decimal:2',
        'balance' => 'decimal:2',
        'synced_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::addGlobalScope('organization', fn ($q) => $q->where('zoho_opening_balances.scope', app(\App\Services\ZohoBooksService::class)->scope()));
        static::creating(function (self $record) {
            $record->scope ??= app(\App\Services\ZohoBooksService::class)->scope();
        });
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
}
