<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TdsLiability extends Model
{
    public const STATUS_OPEN = 'Open';
    public const STATUS_PARTIALLY_PAID = 'PartiallyPaid';
    public const STATUS_PAID = 'Paid';

    protected $fillable = [
        'employee_id',
        'payroll_run_id',
        'payslip_id',
        'financial_year',
        'quarter',
        'period',
        'taxable_amount',
        'tds_deducted',
        'tds_paid',
        'due_date',
        'status',
    ];

    protected $casts = [
        'period' => 'date',
        'due_date' => 'date',
        'taxable_amount' => 'decimal:2',
        'tds_deducted' => 'decimal:2',
        'tds_paid' => 'decimal:2',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function payrollRun(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class);
    }

    public function payslip(): BelongsTo
    {
        return $this->belongsTo(Payslip::class);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(ChallanAllocation::class);
    }

    public function outstandingAmount(): float
    {
        return round((float) $this->tds_deducted - (float) $this->tds_paid, 2);
    }
}
