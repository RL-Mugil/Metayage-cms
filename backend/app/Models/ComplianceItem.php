<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ComplianceItem extends Model
{
    protected $guarded = [];

    protected $casts = [
        'deadline' => 'date:Y-m-d',
        'notes' => 'array',
        'resolved_at' => 'datetime',
    ];

    public function assigneeUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }
}
