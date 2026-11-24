<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Notification extends Model
{
    protected $fillable = [
        'user_id',
        'title',
        'message',
        'channel',
        'is_read',
        'action_url',
    ];

    protected $casts = [
        'is_read' => 'boolean',
    ];

    protected static function booted(): void
    {
        // Each person's channel choice per kind (Settings → Notifications): file quietly and/or email a copy.
        static::creating(fn (self $n) => \App\Services\NotificationChannels::apply($n, false));
        static::created(fn (self $n) => \App\Services\NotificationChannels::apply($n, true));
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
