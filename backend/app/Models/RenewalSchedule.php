<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One renewal fee due on a granted patent. India: annual renewal for years 3-20.
 * US: maintenance fee at 3.5 / 7.5 / 11.5 years, stored as renewal_year 4 / 8 / 12
 * with a label and its payment window (see DocketRules::usMaintenanceSchedule()).
 */
class RenewalSchedule extends Model
{
    protected $fillable = [
        'patent_application_id', 'renewal_year', 'label', 'due_date', 'window_opens_on', 'grace_ends_on',
        'status', 'paid_at', 'patent_invoice_in_id',
    ];

    protected $casts = [
        'due_date'        => 'date',
        'window_opens_on' => 'date',
        'grace_ends_on'   => 'date',
        'paid_at'         => 'date',
    ];

    protected $appends = ['display_label'];

    public function application()
    {
        return $this->belongsTo(PatentApplication::class, 'patent_application_id');
    }

    public function invoice()
    {
        return $this->belongsTo(PatentInvoiceIn::class, 'patent_invoice_in_id');
    }

    /** "Maintenance fee (3.5 years)" for a US row, else "Renewal year 5". */
    public function getDisplayLabelAttribute(): string
    {
        return $this->label ?: "Renewal year {$this->renewal_year}";
    }

    public function isUsMaintenance(): bool
    {
        return $this->label !== null && str_starts_with($this->label, 'Maintenance fee');
    }
}
