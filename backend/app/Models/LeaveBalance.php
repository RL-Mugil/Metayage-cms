<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeaveBalance extends Model
{
    protected $fillable = [
        'employee_id',
        'year',
        'earned_leave',
        'casual_leave',
        'sick_leave',
        'maternity_leave',
        'lop_days',
    ];

    protected $casts = [
        'earned_leave' => 'decimal:2',
        'casual_leave' => 'decimal:2',
        'sick_leave' => 'decimal:2',
        'maternity_leave' => 'decimal:2',
        'lop_days' => 'decimal:2',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
