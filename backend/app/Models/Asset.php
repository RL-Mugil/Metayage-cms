<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Asset extends Model
{
    protected $fillable = [
        'asset_tag',
        'name',
        'category',
        'brand',
        'model',
        'serial_number',
        'status',
        'assigned_to_employee_id',
        'allocated_date',
        'returned_date',
    ];

    protected $casts = [
        'allocated_date' => 'date',
        'returned_date' => 'date',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'assigned_to_employee_id');
    }
}
