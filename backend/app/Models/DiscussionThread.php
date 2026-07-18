<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DiscussionThread extends Model
{
    protected $fillable = [
        'project_id',
        'client_id',
        'title',
        'tag',
        'kind',
        'is_private',
        'status',
    ];

    protected $casts = [
        'is_private' => 'boolean',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(DiscussionMessage::class, 'thread_id');
    }

    public function reads(): HasMany
    {
        return $this->hasMany(DiscussionMessageRead::class, 'thread_id');
    }
}
