<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PerformanceReview extends Model
{
    protected $guarded = [];

    protected $casts = [
        'scores' => 'array',
        'rating' => 'float',
    ];
}
