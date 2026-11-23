<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One line of a case's work-stage log: a stage started or completed on a date. */
class ProjectStageEvent extends Model
{
    public const SOURCE_LABELS = [
        'manual' => 'Recorded',
        'bulk' => 'Back-logged',
        'tracker' => 'Project Tracker',
        'stage_picker' => 'Stage moved',
        'docket' => 'Docket event',
        'status' => 'Case status',
    ];

    protected $fillable = ['project_id', 'project_stage_id', 'stage_name', 'event', 'event_date', 'source', 'notes', 'created_by'];

    protected $casts = ['event_date' => 'date'];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function stage(): BelongsTo
    {
        return $this->belongsTo(ProjectStage::class, 'project_stage_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
