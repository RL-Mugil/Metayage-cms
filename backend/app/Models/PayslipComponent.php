<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Snapshot of one earning line on a payslip, frozen at generation time. */
class PayslipComponent extends Model
{
    protected $fillable = [
        'payslip_id', 'code', 'name', 'calc_type', 'category', 'value', 'percentage_of', 'sequence', 'amount',
    ];

    protected $casts = [
        'sequence' => 'integer',
        'amount'   => 'decimal:2',
        'value'    => 'decimal:2',
    ];

    public function payslip(): BelongsTo
    {
        return $this->belongsTo(Payslip::class);
    }
}
