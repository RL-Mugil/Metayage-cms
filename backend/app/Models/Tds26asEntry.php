<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One TDS credit from an uploaded Form 26AS (Part I). */
class Tds26asEntry extends Model
{
    protected $table = 'tds_26as_entries';

    protected $fillable = ['tan', 'deductor_name', 'fy', 'quarter', 'section', 'transaction_date', 'booking_status', 'amount_paid', 'tds', 'fingerprint', 'upload_batch'];

    protected $casts = ['transaction_date' => 'date', 'amount_paid' => 'decimal:2', 'tds' => 'decimal:2'];
}
