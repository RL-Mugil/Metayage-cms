<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Payslip extends Model
{
    protected $fillable = [
        'payroll_run_id',
        'employee_id',
        'employee_name',
        'employee_code',
        'designation',
        'gross_salary',
        'lop_days',
        'lop_deduction',
        'basic',
        'hra',
        'special_allowance',
        'pf_employee',
        'esi_employee',
        'professional_tax',
        'educational_cess',
        'tds',
        'health_insurance',
        'other_deductions',
        'total_deductions',
        'net_pay',
        'reimbursements',
        'paid_at',
        'withheld',
        'withhold_reason',
        'released_in_run_id',
        'withheld_release_amount',
        'lop_adjustment_amount',
        'hours_logged_minutes',
        'annual_hours_shortage_minutes',
        'annual_hours_adjustment',
    ];

    protected $casts = [
        'gross_salary' => 'decimal:2',
        'lop_days' => 'decimal:1',
        'lop_deduction' => 'decimal:2',
        'basic' => 'decimal:2',
        'hra' => 'decimal:2',
        'special_allowance' => 'decimal:2',
        'pf_employee' => 'decimal:2',
        'esi_employee' => 'decimal:2',
        'professional_tax' => 'decimal:2',
        'educational_cess' => 'decimal:2',
        'tds' => 'decimal:2',
        'health_insurance' => 'decimal:2',
        'other_deductions' => 'decimal:2',
        'total_deductions' => 'decimal:2',
        'net_pay' => 'decimal:2',
        'reimbursements' => 'decimal:2',
        'paid_at' => 'datetime',
        'withheld' => 'boolean',
        'withheld_release_amount' => 'decimal:2',
        'lop_adjustment_amount' => 'decimal:2',
        'hours_logged_minutes' => 'integer',
        'annual_hours_shortage_minutes' => 'integer',
        'annual_hours_adjustment' => 'decimal:2',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class, 'payroll_run_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** Dynamic earning-component breakdown, snapshotted at generation time. */
    public function components(): HasMany
    {
        return $this->hasMany(PayslipComponent::class)->orderBy('sequence');
    }

    /** Reimbursement claims swept into this payslip's `reimbursements` total. */
    public function reimbursementClaims(): HasMany
    {
        return $this->hasMany(ExpenseClaim::class, 'payslip_id');
    }

    public function releasedInRun(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class, 'released_in_run_id');
    }

    public function internalSignature(): HasOne
    {
        return $this->hasOne(InternalDocumentSignature::class, 'document_id')
            ->where('document_type', 'payslip_monthly');
    }
}
