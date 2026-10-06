<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PoiWindow extends Model
{
    protected $fillable = [
        'financial_year',
        'deadline_at',
        'effective_payroll_month',
        'updated_by_id',
    ];

    protected $casts = [
        'deadline_at' => 'date',
        'effective_payroll_month' => 'date',
    ];

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_id');
    }

    public function isOpen(): bool
    {
        return now()->lte($this->deadline_at);
    }
}
