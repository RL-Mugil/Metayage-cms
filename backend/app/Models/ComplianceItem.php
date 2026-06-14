<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ComplianceItem extends Model
{
    protected $fillable = ['matter', 'type', 'jurisdiction', 'deadline', 'action_required', 'assignee', 'assignee_id', 'status', 'notes', 'resolved_at'];

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
