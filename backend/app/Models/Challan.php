<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Challan extends Model
{
    public const STATUS_UNASSOCIATED = 'Unassociated';
    public const STATUS_PARTIALLY_ASSOCIATED = 'PartiallyAssociated';
    public const STATUS_ASSOCIATED = 'Associated';

    protected $fillable = [
        'bsr_code',
        'payment_date',
        'serial_number',
        'amount',
        'interest_amount',
        'penalty_amount',
        'financial_year',
        'quarter',
        'status',
        'recorded_by_id',
        'notes',
    ];

    protected $casts = [
        'payment_date' => 'date',
        'amount' => 'decimal:2',
        'interest_amount' => 'decimal:2',
        'penalty_amount' => 'decimal:2',
    ];

    public function allocations(): HasMany
    {
        return $this->hasMany(ChallanAllocation::class);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_id');
    }

    public function allocatedTotal(): float
    {
        return round((float) $this->allocations->sum('amount'), 2);
    }

    public function unallocatedAmount(): float
    {
        return round((float) $this->amount - $this->allocatedTotal(), 2);
    }
}
