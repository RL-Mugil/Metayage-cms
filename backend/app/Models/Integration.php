<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Integration extends Model
{
    protected $fillable = ['slug', 'name', 'description', 'category', 'initials', 'color', 'connected', 'last_sync', 'sync_freq', 'config'];

    protected $casts = [
        'connected' => 'boolean',
        'config' => 'array',
    ];

    protected $hidden = ['config']; // may contain API keys — never ship to the browser
}
