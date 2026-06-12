<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ComplianceItem extends Model
{
    protected $guarded = [];

    protected $casts = [
        'deadline' => 'date:Y-m-d',
        'notes' => 'array',
        'resolved_at' => 'datetime',
    ];
}
