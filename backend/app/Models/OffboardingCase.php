<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OffboardingCase extends Model
{
    protected $fillable = ['employee', 'employee_id', 'dept', 'last_day', 'exit_type', 'status', 'checklist', 'assigned_hr', 'assigned_hr_id', 'completed_label'];

    protected $casts = [
        'checklist' => 'array',
    ];

    public function employeeRecord(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    public function assignedHrUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_hr_id');
    }
}
