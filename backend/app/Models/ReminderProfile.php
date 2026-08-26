<?php

namespace App\Models;

use App\Models\Concerns\BelongsToFirm;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReminderProfile extends Model
{
    use BelongsToFirm;

    protected $fillable = ['user_id', 'name', 'frequency', 'timezone', 'send_time', 'horizon_days', 'recipients',
        'filters', 'columns', 'color_bands', 'send_empty', 'email_enabled', 'in_app_enabled', 'critical_alerts_enabled', 'active', 'last_sent_at'];

    protected $casts = ['recipients' => 'array', 'filters' => 'array', 'columns' => 'array', 'color_bands' => 'array',
        'send_empty' => 'boolean', 'email_enabled' => 'boolean', 'in_app_enabled' => 'boolean',
        'critical_alerts_enabled' => 'boolean', 'active' => 'boolean', 'last_sent_at' => 'datetime'];

    public function user(): BelongsTo { return $this->belongsTo(User::class); }
}
