<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DiscussionMessage extends Model
{
    protected $fillable = [
        'thread_id',
        'author_id',
        'content',
        'attachments',
    ];

    protected $casts = [
        'attachments' => 'array',
    ];

    public function thread(): BelongsTo
    {
        return $this->belongsTo(DiscussionThread::class, 'thread_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }
}
