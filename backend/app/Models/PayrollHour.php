<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PayrollHour extends Model
{
    protected $fillable = ['employee_id', 'period', 'minutes', 'entered_by_id'];
    protected $casts = ['period' => 'date', 'minutes' => 'integer'];
}
