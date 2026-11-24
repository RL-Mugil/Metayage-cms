<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A payment received against a register invoice (imported from Zoho Books customer payments). */
class RegisterPayment extends Model
{
    protected $fillable = [
        'patent_invoice_in_id', 'client_id', 'zoho_payment_id', 'payment_number', 'payment_date',
        'amount', 'currency', 'exchange_rate', 'amount_bcy', 'payment_mode', 'reference', 'estimated',
        'source', 'recorded_by_id',
    ];

    protected $casts = [
        'payment_date' => 'date:Y-m-d',
        'amount' => 'decimal:2',
        'exchange_rate' => 'decimal:6',
        'amount_bcy' => 'decimal:2',
        'estimated' => 'boolean',
    ];

    public function recordedBy(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_id');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(PatentInvoiceIn::class, 'patent_invoice_in_id');
    }
}
