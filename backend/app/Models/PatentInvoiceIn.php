<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PatentInvoiceIn extends Model
{
    protected $table = 'patent_invoices_in';

    protected $fillable = [
        'type', 'status', 'project_id', 'client_id', 'created_by_id',
        'docket_number', 'invoice_uin',
        'invoice_date', 'tax_invoice_date', 'tax_serial_number',
        'client_code_prefix', 'invention_number', 'patent_office_code',
        'first_inventor_name', 'invention_title', 'service_code',
        'client_name', 'client_reference', 'state_of_supply',
        'entity_status', 'patent_office_application_number',
        'additional_information', 'patent_office_acknowledgement',
        'remarks', 'uin_old', 'uin_old_2',
        'patent_office_fees', 'service_fees', 'discount_percentage', 'discount_amount', 'other_expenses',
        'igst_amount', 'cgst_amount', 'sgst_amount',
        'invoice_amount', 'attorney_fees', 'consultant_fees',
        'referral_fees', 'net_revenue', 'currency',
        // Renewal approve -> invoice -> proof -> confirm tracking (RenewalActionController)
        'payment_status', 'proof_document_id', 'status_note', 'status_note_by_id', 'status_note_at',
        'payment_confirmed_at',
    ];

    protected $casts = [
        'invoice_date'        => 'date:Y-m-d',
        'tax_invoice_date'    => 'date:Y-m-d',
        'patent_office_fees'  => 'decimal:2',
        'service_fees'        => 'decimal:2',
        'discount_percentage' => 'decimal:2',
        'discount_amount'     => 'decimal:2',
        'other_expenses'      => 'decimal:2',
        'igst_amount'         => 'decimal:2',
        'cgst_amount'         => 'decimal:2',
        'sgst_amount'         => 'decimal:2',
        'invoice_amount'      => 'decimal:2',
        'attorney_fees'       => 'decimal:2',
        'consultant_fees'     => 'decimal:2',
        'referral_fees'       => 'decimal:2',
        'net_revenue'         => 'decimal:2',
        'status_note_at'      => 'datetime',
        'payment_confirmed_at' => 'datetime',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function proofDocument(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'proof_document_id');
    }

    public function statusNoteBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'status_note_by_id');
    }

    public function renewalSchedules(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(RenewalSchedule::class, 'patent_invoice_in_id');
    }
}
