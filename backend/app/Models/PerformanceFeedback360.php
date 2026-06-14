<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PerformanceFeedback360 extends Model
{
    protected $table = 'performance_feedback360';

    protected $fillable = ['from_name', 'to_name', 'sent_label', 'status'];
}
