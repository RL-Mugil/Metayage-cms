<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FeedbackEntry extends Model
{
    protected $fillable = ['client_name', 'rating', 'comment', 'category', 'entry_date'];

    protected $casts = [
        'entry_date' => 'date:Y-m-d',
        'rating' => 'integer',
    ];
}
