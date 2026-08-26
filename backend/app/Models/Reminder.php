<?php

namespace App\Models;

use App\Models\Concerns\BelongsToFirm;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Reminder extends Model
{
    use BelongsToFirm;

    protected $fillable = ['user_id', 'assigned_user_id', 'docket_deadline_id', 'title', 'description', 'category', 'due_date', 'due_time', 'scope', 'completed', 'source', 'acknowledged_at', 'acknowledged_by'];

    protected $casts = [
        'due_date' => 'date:Y-m-d',
        'completed' => 'boolean',
        'acknowledged_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function assignee(): BelongsTo { return $this->belongsTo(User::class, 'assigned_user_id'); }
    public function deadline(): BelongsTo { return $this->belongsTo(DocketDeadline::class, 'docket_deadline_id'); }
}
