<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FeedbackEntry extends Model
{
    protected $guarded = [];

    protected $casts = [
        'entry_date' => 'date:Y-m-d',
        'rating' => 'integer',
    ];
}
