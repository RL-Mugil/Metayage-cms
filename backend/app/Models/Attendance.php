<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Attendance extends Model
{
    protected $fillable = [
        'employee_id',
        'attendance_date',
        'check_in',
        'check_out',
        'capture_method',
        'location_gps',
        'status',
        'duration_minutes',
        'regularized',
        'regularization_reason',
    ];

    protected $casts = [
        'attendance_date' => 'date',
        'regularized' => 'boolean',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
