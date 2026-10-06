<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvestmentDeclaration extends Model
{
    public const STATUS_DRAFT = 'Draft';
    public const STATUS_SUBMITTED = 'Submitted';
    public const STATUS_APPROVED = 'Approved';
    public const STATUS_REJECTED = 'Rejected';

    protected $fillable = [
        'employee_id',
        'financial_year',
        'regime_choice',
        'section_80c_amount',
        'section_80ccd1b_amount',
        'section_80d_amount',
        'section_80e_amount',
        'section_80g_amount',
        'section_80tta_amount',
        'hra_rent_paid',
        'housing_type',
        'home_loan_interest_declared',
        'other_income_declared',
        'previous_employer_salary',
        'lta_exemption_claimed',
        'section_80gg_amount',
        'section_80dd_amount',
        'section_80ddb_amount',
        'section_80ee_amount',
        'section_80eea_amount',
        'section_80gga_amount',
        'section_80ggc_amount',
        'other_deductions_json',
        'status',
        'approved_by_id',
    ];

    protected $casts = [
        'section_80c_amount' => 'decimal:2',
        'section_80ccd1b_amount' => 'decimal:2',
        'section_80d_amount' => 'decimal:2',
        'section_80e_amount' => 'decimal:2',
        'section_80g_amount' => 'decimal:2',
        'section_80tta_amount' => 'decimal:2',
        'hra_rent_paid' => 'decimal:2',
        'home_loan_interest_declared' => 'decimal:2',
        'other_income_declared' => 'decimal:2',
        'previous_employer_salary' => 'decimal:2',
        'lta_exemption_claimed' => 'decimal:2',
        'section_80gg_amount' => 'decimal:2',
        'section_80dd_amount' => 'decimal:2',
        'section_80ddb_amount' => 'decimal:2',
        'section_80ee_amount' => 'decimal:2',
        'section_80eea_amount' => 'decimal:2',
        'section_80gga_amount' => 'decimal:2',
        'section_80ggc_amount' => 'decimal:2',
        'other_deductions_json' => 'array',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_id');
    }
}
