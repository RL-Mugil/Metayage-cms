<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContractorPayment extends Model
{
    protected $fillable = [
        'contractor_id',
        'invoice_number',
        'invoice_date',
        'amount',
        'tds_deducted',
        'net_paid',
        'status',
        'paid_at',
        'recorded_by_id',
    ];

    protected $casts = [
        'invoice_date' => 'date',
        'amount' => 'decimal:2',
        'tds_deducted' => 'decimal:2',
        'net_paid' => 'decimal:2',
        'paid_at' => 'datetime',
    ];

    public function contractor(): BelongsTo
    {
        return $this->belongsTo(Contractor::class);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_id');
    }
}
