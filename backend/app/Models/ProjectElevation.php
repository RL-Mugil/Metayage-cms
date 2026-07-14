<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectElevation extends Model
{
    protected $fillable = [
        'project_id',
        'predecessor_project_id',
        'from_service_code',
        'to_service_code',
        'from_docket',
        'to_docket',
        'elevated_at',
        'elevated_by_id',
        'note',
        'is_retroactive_link',
    ];

    protected $casts = [
        'elevated_at'         => 'datetime',
        'is_retroactive_link' => 'boolean',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function predecessorProject(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'predecessor_project_id');
    }

    public function elevatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'elevated_by_id');
    }
}
