<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChallanAllocation extends Model
{
    protected $fillable = [
        'challan_id',
        'tds_liability_id',
        'amount',
        'associated_by_id',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    public function challan(): BelongsTo
    {
        return $this->belongsTo(Challan::class);
    }

    public function tdsLiability(): BelongsTo
    {
        return $this->belongsTo(TdsLiability::class);
    }

    public function associatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'associated_by_id');
    }
}
