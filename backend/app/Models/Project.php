<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Project extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'project_code', 'matter_reference', 'client_id', 'project_type', 'project_name',
        'invention_title', 'technology_field', 'parent_project_id', 'priority_date',
        'assigned_partner_id', 'assigned_manager_id', 'assigned_team',
        'start_date', 'target_filing_date', 'hard_deadline',
        'fee_arrangement',
        'status', 'urgency', 'confidentiality_level', 'tags',
        // IP matter fields
        'docket_number', 'application_number', 'patent_office_code', 'service_code',
        'case_type', 'filing_date', 'secondary_manager_id', 'patent_engineer_id', 'notes',
    ];

    protected $casts = [
        'assigned_team'  => 'array',
        'tags'           => 'array',
        'priority_date'  => 'date',
        'start_date'     => 'date',
        'target_filing_date' => 'date',
        'hard_deadline'  => 'date',
        'filing_date'    => 'date',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function parentProject(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'parent_project_id');
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_partner_id');
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_manager_id');
    }

    public function stages(): HasMany
    {
        return $this->hasMany(ProjectStage::class)->orderBy('sequence_order');
    }

    public function secondaryManager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'secondary_manager_id');
    }

    public function patentEngineer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'patent_engineer_id');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }
}
