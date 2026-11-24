<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RenewalDecision extends Model
{
    public const DECISIONS = ['renew', 'lapse', 'ask'];

    protected $fillable = ['renewal_schedule_id', 'client_id', 'decision', 'note', 'decided_by_id'];

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(RenewalSchedule::class, 'renewal_schedule_id');
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by_id');
    }
}
