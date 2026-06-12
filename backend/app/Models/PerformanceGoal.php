<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PerformanceGoal extends Model
{
    protected $guarded = [];

    protected $casts = [
        'progress' => 'integer',
    ];

    public function employeeRecord(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }
}
