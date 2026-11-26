<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Per client: reminders paused until a date, and preferred reminder recipients. */
class CollectionClientSetting extends Model
{
    protected $table = 'collection_client_settings';

    protected $fillable = ['client_id', 'paused_until', 'recipients', 'note'];

    protected $casts = ['paused_until' => 'date:Y-m-d', 'recipients' => 'array'];
}
