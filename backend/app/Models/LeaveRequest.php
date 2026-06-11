<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeaveRequest extends Model
{
    protected $fillable = [
        'employee_id',
        'leave_type',
        'from_date',
        'to_date',
        'total_days',
        'reason',
        'attachment_path',
        'status',
        'approved_by_id',
        'comments',
    ];

    protected $casts = [
        'from_date' => 'date',
        'to_date' => 'date',
        'total_days' => 'decimal:1',
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
