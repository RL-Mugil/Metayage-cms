<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One follow-up on an unpaid invoice: what was said, and any date the client promised to pay. */
class InvoiceFollowUp extends Model
{
    protected $table = 'invoice_follow_ups';

    protected $fillable = ['patent_invoice_in_id', 'user_id', 'note', 'promised_on'];

    protected $casts = ['promised_on' => 'date:Y-m-d'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
