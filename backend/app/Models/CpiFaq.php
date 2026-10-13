<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CpiFaq extends Model
{
    protected $fillable = ['question', 'answer', 'sort_order', 'active'];

    protected $casts = ['active' => 'boolean', 'sort_order' => 'integer'];
}
