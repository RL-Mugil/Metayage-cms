<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClientLedger extends Model
{
    protected $table = 'client_ledger';

    protected $fillable = [
        'client_id',
        'transaction_date',
        'document_type',
        'document_reference',
        'debit',
        'credit',
        'balance',
        'notes',
    ];

    protected $casts = [
        'transaction_date' => 'date',
        'debit' => 'decimal:2',
        'credit' => 'decimal:2',
        'balance' => 'decimal:2',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
}
