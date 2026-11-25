<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Accounts' approval to send a quote to Zoho, valid only for the exact draft (version) approved. */
class QuoteSendApproval extends Model
{
    protected $table = 'quote_send_approvals';

    protected $fillable = ['quote_kind', 'quote_id', 'customer_id', 'version', 'requested_by_id', 'status', 'decided_by_id', 'decided_at', 'note'];

    protected $casts = ['decided_at' => 'datetime'];

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_id');
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by_id');
    }
}
