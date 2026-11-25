<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A month closed for portal edits (YYYY-MM). Zoho imports still apply and are flagged. */
class FinancePeriodLock extends Model
{
    protected $table = 'finance_period_locks';

    protected $fillable = ['month', 'locked_by_id', 'locked_at', 'note'];

    protected $casts = ['locked_at' => 'datetime'];

    public function lockedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'locked_by_id');
    }
}
