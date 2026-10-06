<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProofOfInvestment extends Model
{
    public const STATUS_PENDING_REVIEW = 'PendingReview';
    public const STATUS_CONSIDERED = 'Considered';

    protected $fillable = [
        'investment_declaration_id',
        'employee_id',
        'financial_year',
        'approved_section_80c_amount',
        'approved_section_80ccd1b_amount',
        'approved_section_80d_amount',
        'approved_section_80e_amount',
        'approved_section_80g_amount',
        'approved_section_80tta_amount',
        'approved_hra_rent_paid',
        'approved_home_loan_interest_amount',
        'approved_other_income_amount',
        'approved_previous_employer_salary',
        'approved_lta_exemption_claimed',
        'approved_section_80gg_amount',
        'approved_section_80dd_amount',
        'approved_section_80ddb_amount',
        'approved_section_80ee_amount',
        'approved_section_80eea_amount',
        'approved_section_80gga_amount',
        'approved_section_80ggc_amount',
        'approved_other_deductions_json',
        'rejection_reasons_json',
        'status',
        'consider_for_it_at',
        'considered_by_id',
    ];

    protected $casts = [
        'approved_section_80c_amount' => 'decimal:2',
        'approved_section_80ccd1b_amount' => 'decimal:2',
        'approved_section_80d_amount' => 'decimal:2',
        'approved_section_80e_amount' => 'decimal:2',
        'approved_section_80g_amount' => 'decimal:2',
        'approved_section_80tta_amount' => 'decimal:2',
        'approved_hra_rent_paid' => 'decimal:2',
        'approved_home_loan_interest_amount' => 'decimal:2',
        'approved_other_income_amount' => 'decimal:2',
        'approved_previous_employer_salary' => 'decimal:2',
        'approved_lta_exemption_claimed' => 'decimal:2',
        'approved_section_80gg_amount' => 'decimal:2',
        'approved_section_80dd_amount' => 'decimal:2',
        'approved_section_80ddb_amount' => 'decimal:2',
        'approved_section_80ee_amount' => 'decimal:2',
        'approved_section_80eea_amount' => 'decimal:2',
        'approved_section_80gga_amount' => 'decimal:2',
        'approved_section_80ggc_amount' => 'decimal:2',
        'approved_other_deductions_json' => 'array',
        'rejection_reasons_json' => 'array',
        'consider_for_it_at' => 'datetime',
    ];

    public function declaration(): BelongsTo
    {
        return $this->belongsTo(InvestmentDeclaration::class, 'investment_declaration_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function consideredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'considered_by_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(InvestmentProofDocument::class);
    }

    public function isConsidered(): bool
    {
        return $this->consider_for_it_at !== null;
    }
}
