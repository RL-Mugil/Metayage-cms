<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Admin-configurable income tax slabs — not hardcoded, since Union Budget
 * changes these every year (same philosophy as Professional Tax). See
 * IncomeTaxComputationService for how slabs_json is applied.
 */
class TaxSlabConfig extends Model
{
    protected $fillable = [
        'financial_year',
        'regime',
        'slabs_json',
        'standard_deduction',
        'rebate_87a_limit',
        'rebate_87a_amount',
        'cess_rate',
    ];

    protected $casts = [
        'slabs_json' => 'array',
        'standard_deduction' => 'decimal:2',
        'rebate_87a_limit' => 'decimal:2',
        'rebate_87a_amount' => 'decimal:2',
        'cess_rate' => 'decimal:2',
    ];
}
