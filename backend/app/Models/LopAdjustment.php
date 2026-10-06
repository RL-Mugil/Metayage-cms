<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LopAdjustment extends Model
{
    public const TYPE_REVERSAL = 'Reversal';
    public const TYPE_CORRECTION = 'Correction';

    public const STATUS_PENDING = 'Pending';
    public const STATUS_APPLIED = 'Applied';

    protected $fillable = [
        'employee_id',
        'payslip_id',
        'adjustment_type',
        'days',
        'period',
        'reason',
        'applied_in_run_id',
        'created_by_id',
        'status',
    ];

    protected $casts = [
        'period' => 'date',
        'days' => 'decimal:2',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function payslip(): BelongsTo
    {
        return $this->belongsTo(Payslip::class);
    }

    public function appliedInRun(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class, 'applied_in_run_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }
}
