<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ReimbursementType extends Model
{
    protected $fillable = [
        'name', 'code', 'monthly_cap', 'active',
    ];

    protected $casts = [
        'monthly_cap' => 'decimal:2',
        'active'      => 'boolean',
    ];

    public function claims(): HasMany
    {
        return $this->hasMany(ExpenseClaim::class);
    }
}
