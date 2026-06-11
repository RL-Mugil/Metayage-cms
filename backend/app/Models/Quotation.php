<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Quotation extends Model
{
    protected $fillable = [
        'quote_code',
        'client_id',
        'project_id',
        'valid_until',
        'fee_structure',
        'estimated_hours',
        'hourly_rates',
        'estimated_disbursements',
        'buffer_percentage',
        'total_amount',
        'currency',
        'status',
        'approved_by_id',
    ];

    protected $casts = [
        'hourly_rates' => 'array',
        'valid_until' => 'date',
        'estimated_hours' => 'decimal:2',
        'estimated_disbursements' => 'decimal:2',
        'buffer_percentage' => 'decimal:2',
        'total_amount' => 'decimal:2',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_id');
    }
}
