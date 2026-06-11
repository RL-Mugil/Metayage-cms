<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectStage extends Model
{
    protected $fillable = [
        'project_id',
        'stage_name',
        'owner_id',
        'duration_days',
        'due_date',
        'actual_start_at',
        'actual_end_at',
        'notes',
        'checklist',
        'gate_criteria',
        'sequence_order',
        'status',
    ];

    protected $casts = [
        'checklist' => 'array',
        'gate_criteria' => 'array',
        'due_date' => 'date',
        'actual_start_at' => 'datetime',
        'actual_end_at' => 'datetime',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }
}
