<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvoiceItem extends Model
{
    protected $fillable = [
        'invoice_id',
        'description',
        'hsn_sac_code',
        'quantity',
        'unit_rate',
        'amount',
        'tax_rate',
    ];

    protected $casts = [
        'quantity' => 'decimal:2',
        'unit_rate' => 'decimal:2',
        'amount' => 'decimal:2',
        'tax_rate' => 'decimal:2',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
