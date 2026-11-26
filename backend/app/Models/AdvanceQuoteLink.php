<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Part of a customer payment's unused amount (an advance) earmarked for a proforma quote, so the
 * quote shows its net due. A portal note only: Zoho applies the money once the quote is invoiced.
 */
class AdvanceQuoteLink extends Model
{
    protected $fillable = ['zoho_payment_id', 'payment_number', 'client_id', 'patent_invoice_in_id', 'amount', 'note', 'created_by_id',
        'applied_zoho_invoice_id', 'applied_at', 'applied_by_id'];

    protected $casts = ['amount' => 'decimal:2', 'applied_at' => 'datetime'];

    public function quote(): BelongsTo
    {
        return $this->belongsTo(PatentInvoiceIn::class, 'patent_invoice_in_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }
}
