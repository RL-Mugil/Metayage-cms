<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PerformanceGoal extends Model
{
    protected $guarded = [];

    protected $casts = [
        'progress' => 'integer',
    ];
}
