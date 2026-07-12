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
        'patent_office_fees', 'service_fees', 'other_expenses',
        'igst_amount', 'cgst_amount', 'sgst_amount',
        'invoice_amount', 'attorney_fees', 'consultant_fees',
        'referral_fees', 'net_revenue', 'currency',
    ];

    protected $casts = [
        'invoice_date'        => 'date:Y-m-d',
        'tax_invoice_date'    => 'date:Y-m-d',
        'patent_office_fees'  => 'decimal:2',
        'service_fees'        => 'decimal:2',
        'other_expenses'      => 'decimal:2',
        'igst_amount'         => 'decimal:2',
        'cgst_amount'         => 'decimal:2',
        'sgst_amount'         => 'decimal:2',
        'invoice_amount'      => 'decimal:2',
        'attorney_fees'       => 'decimal:2',
        'consultant_fees'     => 'decimal:2',
        'referral_fees'       => 'decimal:2',
        'net_revenue'         => 'decimal:2',
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
}
