<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What settled part of a register invoice (imported from Zoho Books): a customer payment
 * (kind "cash"), TDS the client withheld on that payment ("tds", not cash in hand) or a credit
 * note ("credit_note"). Cash figures use only cash().
 */
class RegisterPayment extends Model
{
    protected $fillable = [
        'patent_invoice_in_id', 'client_id', 'zoho_payment_id', 'payment_number', 'payment_date',
        'amount', 'currency', 'exchange_rate', 'amount_bcy', 'payment_mode', 'reference', 'estimated',
        'source', 'recorded_by_id', 'kind',
    ];

    public const CASH = 'cash';
    public const TDS = 'tds';
    public const CREDIT_NOTE = 'credit_note';

    protected $casts = [
        'payment_date' => 'date:Y-m-d',
        'amount' => 'decimal:2',
        'exchange_rate' => 'decimal:6',
        'amount_bcy' => 'decimal:2',
        'estimated' => 'boolean',
    ];

    /** Money actually received (not TDS withheld, not credit notes). */
    public function scopeCash($query)
    {
        return $query->where('kind', self::CASH);
    }

    public function recordedBy(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_id');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(PatentInvoiceIn::class, 'patent_invoice_in_id');
    }
}
