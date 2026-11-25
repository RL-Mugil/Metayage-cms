<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
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

    /**
     * Tasks a user may see (Tasks page, AI tools): a client its own client's tasks, Patent Analysts and
     * paralegals their assigned tasks, a galvanizer the circle, an inventor their cases; others all.
     */
    public function scopeVisibleTo(\Illuminate\Database\Eloquent\Builder $query, User $user, ?Client $portalClient = null): \Illuminate\Database\Eloquent\Builder
    {
        if ($user->isClientRole()) {
            $client = $portalClient ?? Client::forUser($user);
            $client ? $query->whereHas('project', fn ($q) => $q->where('client_id', $client->id)) : $query->whereRaw('1=0');
        } elseif (in_array($user->role, ['associate', 'paralegal'], true)) {
            $query->where('assignee_id', $user->id);
        } elseif ($user->isGalvanizer()) {
            $query->whereHas('project', fn ($q) => $user->applyProjectScope($q));
        } elseif ($user->isInventor()) {
            $query->whereHas('project.inventors', fn ($q) => $q->where('inventors.user_id', $user->id));
        }

        return $query;
    }

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

    /** Documents attached to this task — either freshly uploaded or picked from
     *  the tagged case's existing documents. Many-to-many so a document already
     *  on this case can be selected for more than one task. */
    public function documents(): BelongsToMany
    {
        return $this->belongsToMany(Document::class, 'document_task');
    }
}
