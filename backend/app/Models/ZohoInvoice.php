<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Local read-only mirror of Zoho Books invoices/estimates, refreshed by the zoho:sync command. */
class ZohoInvoice extends Model
{
    protected $fillable = [
        'zoho_id', 'zoho_type', 'zoho_contact_id', 'client_id', 'project_id',
        'number', 'status', 'total', 'balance', 'currency', 'txn_date', 'due_date',
        'url', 'application_no', 'patent_office', 'match_source', 'synced_at',
    ];

    protected $casts = [
        'total'      => 'decimal:2',
        'balance'    => 'decimal:2',
        'txn_date'   => 'date',
        'due_date'   => 'date',
        'synced_at'  => 'datetime',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
