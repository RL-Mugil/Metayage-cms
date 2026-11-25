<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A client's TAN as it appears in Form 26AS (kept apart from the clients table). */
class TdsDeductor extends Model
{
    protected $table = 'tds_deductors';

    protected $fillable = ['tan', 'name', 'client_id', 'matched_by'];

    protected $casts = [];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
}
