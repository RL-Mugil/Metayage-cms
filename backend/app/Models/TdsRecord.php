<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** TDS the client withheld on one payment: is it in Form 26AS, has the Form 16A come in? */
class TdsRecord extends Model
{
    protected $table = 'tds_records';

    protected $fillable = ['register_payment_id', 'client_id', 'patent_invoice_in_id', 'payment_number', 'deducted_on', 'fy', 'quarter', 'amount', 'status', 'tds_26as_entry_id', 'form16a_received_on', 'form16a_certificate_no', 'form16a_document_id', 'note'];

    protected $casts = ['deducted_on' => 'date', 'form16a_received_on' => 'date', 'amount' => 'decimal:2'];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(PatentInvoiceIn::class, 'patent_invoice_in_id');
    }

    public function entry(): BelongsTo
    {
        return $this->belongsTo(Tds26asEntry::class, 'tds_26as_entry_id');
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'form16a_document_id');
    }
}
