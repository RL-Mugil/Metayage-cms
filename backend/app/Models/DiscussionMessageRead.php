<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DiscussionMessageRead extends Model
{
    protected $fillable = [
        'thread_id',
        'user_id',
        'last_read_message_id',
        'read_at',
    ];

    protected $casts = [
        'last_read_message_id' => 'integer',
        'read_at'              => 'datetime',
    ];

    public function thread(): BelongsTo
    {
        return $this->belongsTo(DiscussionThread::class, 'thread_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
