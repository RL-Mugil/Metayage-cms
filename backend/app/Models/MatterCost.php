<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** An amount the firm paid out on a matter: official fee, foreign associate bill or other cost. */
class MatterCost extends Model
{
    use SoftDeletes;

    public const KINDS = ['official_fee' => 'Official fee', 'associate' => 'Foreign associate', 'other' => 'Other cost'];

    protected $fillable = [
        'project_id', 'client_id', 'kind', 'vendor_name', 'vendor_reference', 'description', 'bill_date', 'paid_on',
        'currency', 'amount', 'exchange_rate', 'amount_bcy', 'recoverable', 'billed_invoice_id', 'source', 'zoho_id',
        'document_id', 'notes', 'created_by_id',
    ];

    protected $casts = [
        'bill_date' => 'date:Y-m-d', 'paid_on' => 'date:Y-m-d', 'amount' => 'decimal:2', 'exchange_rate' => 'decimal:6',
        'amount_bcy' => 'decimal:2', 'recoverable' => 'boolean',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function billedInvoice(): BelongsTo
    {
        return $this->belongsTo(PatentInvoiceIn::class, 'billed_invoice_id');
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }
}
