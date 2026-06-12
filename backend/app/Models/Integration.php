<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Integration extends Model
{
    protected $guarded = [];

    protected $casts = [
        'connected' => 'boolean',
        'config' => 'array',
    ];

    protected $hidden = ['config']; // may contain API keys — never ship to the browser
}
