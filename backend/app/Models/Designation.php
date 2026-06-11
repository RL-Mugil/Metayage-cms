<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Designation extends Model
{
    protected $fillable = [
        'title',
        'description',
        'grade_band',
    ];

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }
}
