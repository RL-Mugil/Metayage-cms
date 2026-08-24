<?php

namespace App\Models;

use App\Models\Concerns\BelongsToFirm;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Project extends Model
{
    use BelongsToFirm, SoftDeletes;

    protected $fillable = [
        'project_code', 'matter_reference', 'client_id', 'invention_family_id', 'project_type', 'project_name',
        'invention_title', 'technology_field', 'parent_project_id', 'priority_date',
        'assigned_partner_id', 'assigned_manager_id', 'assigned_team',
        'start_date', 'target_filing_date', 'hard_deadline',
        'idf_received_date', 'advance_payment_date', 'partial_payment_date', 'full_payment_date',
        'status', 'urgency', 'tags', 'tracker_status_synced_at',
        // IP matter fields
        'docket_number', 'original_docket', 'invention_number', 'docket_trak_ref', 'application_number', 'patent_office_code', 'service_code',
        'case_type', 'filing_date', 'patent_granted', 'secondary_manager_id', 'patent_engineer_id', 'notes', 'circle',
        'google_task_ids', 'patent_application_id', 'jurisdiction_lifecycle_template_id',
        'lifecycle_template_version', 'docket_reviewer_id',
    ];

    protected $casts = [
        'assigned_team'       => 'array',
        'tags'                => 'array',
        'priority_date'       => 'date',
        'start_date'          => 'date',
        'target_filing_date'  => 'date',
        'hard_deadline'       => 'date',
        'filing_date'         => 'date',
        'patent_granted'      => 'boolean',
        'idf_received_date'   => 'date',
        'advance_payment_date'=> 'date',
        'partial_payment_date'=> 'date',
        'full_payment_date'   => 'date',
        'google_task_ids'     => 'array',
        'tracker_status_synced_at' => 'datetime',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function parentProject(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'parent_project_id');
    }

    public function patentApplication(): BelongsTo
    {
        return $this->belongsTo(PatentApplication::class, 'patent_application_id');
    }

    public function inventionFamily(): BelongsTo
    {
        return $this->belongsTo(InventionFamily::class);
    }

    public function lifecycleTemplate(): BelongsTo
    {
        return $this->belongsTo(JurisdictionLifecycleTemplate::class, 'jurisdiction_lifecycle_template_id');
    }

    public function docketReviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'docket_reviewer_id');
    }

    /** inventor-role Users listed as inventor-of-record on this case (see project_inventors). */
    public function inventors(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(User::class, 'project_inventors', 'project_id', 'user_id')->withTimestamps();
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
