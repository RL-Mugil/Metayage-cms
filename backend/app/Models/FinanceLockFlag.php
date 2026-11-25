<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A change that reached a locked month (from a Zoho import), for Accounts to review. */
class FinanceLockFlag extends Model
{
    protected $table = 'finance_lock_flags';

    protected $fillable = ['month', 'subject_type', 'subject_id', 'reference', 'what', 'client_id', 'reviewed_by_id', 'reviewed_at'];

    protected $casts = ['reviewed_at' => 'datetime'];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_id');
    }
}
