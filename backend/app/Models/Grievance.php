<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Grievance extends Model
{
    protected $fillable = [
        'employee_id',
        'anonymous',
        'category',
        'description',
        'status',
        'assigned_hr_id',
        'resolution',
        'resolved_at',
    ];

    protected $casts = [
        'anonymous' => 'boolean',
        'resolved_at' => 'datetime',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function assignedHR(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_hr_id');
    }
}
