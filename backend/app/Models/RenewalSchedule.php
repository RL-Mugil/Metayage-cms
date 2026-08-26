<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RenewalSchedule extends Model
{
    protected $fillable = [
        'patent_application_id', 'renewal_year', 'due_date', 'status', 'paid_at', 'patent_invoice_in_id',
    ];

    protected $casts = [
        'due_date' => 'date',
        'paid_at'  => 'date',
    ];

    public function application()
    {
        return $this->belongsTo(PatentApplication::class, 'patent_application_id');
    }

    public function invoice()
    {
        return $this->belongsTo(PatentInvoiceIn::class, 'patent_invoice_in_id');
    }
}
