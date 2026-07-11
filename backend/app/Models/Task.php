<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Task extends Model
{
    protected $fillable = [
        'task_code',
        'google_task_id',
        'project_id',
        'title',
        'description',
        'assignee_id',
        'assigned_by_id',
        'reviewer_id',
        'priority',
        'due_date',
        'actual_hours',
        'status',
        'dependencies',
        'tags',
        'recurring',
        'recurrence_pattern',
        'billable',
    ];

    protected $casts = [
        'dependencies' => 'array',
        'tags' => 'array',
        'due_date' => 'datetime',
        'actual_hours' => 'decimal:2',
        'recurring' => 'boolean',
        'billable' => 'boolean',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }

    public function timeEntries(): HasMany
    {
        return $this->hasMany(TimeEntry::class);
    }
}
