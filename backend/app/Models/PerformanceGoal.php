<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PerformanceGoal extends Model
{
    protected $fillable = ['title', 'employee', 'employee_id', 'due_label', 'progress', 'status'];

    protected $casts = [
        'progress' => 'integer',
    ];

    public function employeeRecord(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }
}
