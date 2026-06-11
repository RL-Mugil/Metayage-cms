<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClientContact extends Model
{
    protected $fillable = [
        'client_id',
        'name',
        'title',
        'department',
        'email',
        'phone',
        'mobile',
        'timezone',
        'preferred_language',
        'notification_preferences',
        'role_type',
        'email_verified_at',
    ];

    protected $casts = [
        'notification_preferences' => 'array',
        'email_verified_at' => 'datetime',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
}
