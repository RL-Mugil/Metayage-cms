<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PayrollRun extends Model
{
    protected $fillable = [
        'period',
        'status',
        'run_type',
        'employee_count',
        'gross_total',
        'deductions_total',
        'net_total',
        'processed_by_id',
        'finalized_at',
        'paid_at',
        'submitted_by_id',
        'submitted_at',
        'approved_by_id',
        'approved_at',
        'rejection_reason',
    ];

    protected $casts = [
        'period' => 'date',
        'finalized_at' => 'datetime',
        'paid_at' => 'datetime',
        'submitted_at' => 'datetime',
        'approved_at' => 'datetime',
        'gross_total' => 'decimal:2',
        'deductions_total' => 'decimal:2',
        'net_total' => 'decimal:2',
    ];

    public function payslips(): HasMany
    {
        return $this->hasMany(Payslip::class);
    }

    public function processedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'processed_by_id');
    }

    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_id');
    }
}
