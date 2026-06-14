<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Reminder extends Model
{
    protected $fillable = ['user_id', 'title', 'description', 'category', 'due_date', 'due_time', 'scope', 'completed', 'source'];

    protected $casts = [
        'due_date' => 'date:Y-m-d',
        'completed' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
