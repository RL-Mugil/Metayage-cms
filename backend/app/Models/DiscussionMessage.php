<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DiscussionMessage extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'thread_id',
        'author_id',
        'content',
        'edited_at',
        'attachments',
        'mentions',
    ];

    protected $casts = [
        'attachments' => 'array',
        'mentions'    => 'array',
        'edited_at'   => 'datetime',
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
