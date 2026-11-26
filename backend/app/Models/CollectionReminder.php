<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A payment reminder drafted for a client at an ageing band; sent only after the Client Manager or Accounts approves. */
class CollectionReminder extends Model
{
    protected $table = 'collection_reminders';

    protected $fillable = ['client_id', 'band', 'invoice_ids', 'amount', 'recipients', 'subject', 'body', 'status', 'escalated', 'sent_by_id', 'sent_at', 'error'];

    protected $casts = ['invoice_ids' => 'array', 'recipients' => 'array', 'amount' => 'decimal:2', 'escalated' => 'boolean', 'sent_at' => 'datetime'];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function sentBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by_id');
    }
}
