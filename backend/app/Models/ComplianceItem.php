<?php

namespace App\Models;

use App\Models\Concerns\BelongsToFirm;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ComplianceItem extends Model
{
    use BelongsToFirm;

    protected $fillable = [
        'client_id', 'project_id', 'patent_application_id', 'source_type', 'source_key',
        'source_metadata', 'matter', 'type', 'jurisdiction', 'deadline', 'action_required',
        'assignee', 'assignee_id', 'status', 'notes', 'resolved_at',
    ];

    protected $casts = [
        'deadline' => 'date:Y-m-d',
        'notes' => 'array',
        'source_metadata' => 'array',
        'resolved_at' => 'datetime',
    ];

    public function assigneeUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function patentApplication(): BelongsTo
    {
        return $this->belongsTo(PatentApplication::class);
    }
}
