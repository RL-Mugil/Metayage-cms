<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Approval extends Model
{
    protected $fillable = [
        'requester_id',
        'approver_id',
        'client_id',
        'project_id',
        'type',
        'kind',
        'title',
        'description',
        'subject_type',
        'subject_id',
        'status',
        'comments',
    ];

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approver_id');
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /** The case this approval request is about — optional, not every approval is case-specific. */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** Documents attached to this request — freshly uploaded or picked from the tagged case. */
    public function documents(): BelongsToMany
    {
        return $this->belongsToMany(Document::class, 'approval_documents');
    }
}
