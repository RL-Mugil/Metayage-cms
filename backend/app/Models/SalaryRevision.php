<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalaryRevision extends Model
{
    public const STATUS_PENDING = 'Pending';
    public const STATUS_APPROVED = 'Approved';
    public const STATUS_REJECTED = 'Rejected';

    protected $fillable = [
        'employee_id',
        'previous_base_pay',
        'new_base_pay',
        'new_hra',
        'effective_period',
        'reason',
        'requested_by_id',
        'status',
        'approved_by_id',
    ];

    protected $casts = [
        'previous_base_pay' => 'decimal:2',
        'new_base_pay' => 'decimal:2',
        'new_hra' => 'decimal:2',
        'effective_period' => 'date',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_id');
    }
}
