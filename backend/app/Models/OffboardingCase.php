<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OffboardingCase extends Model
{
    protected $guarded = [];

    protected $casts = [
        'checklist' => 'array',
    ];
}
