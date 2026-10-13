<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CpiBlackout extends Model
{
    protected $fillable = ['day', 'note'];

    protected $casts = ['day' => 'date:Y-m-d'];
}
