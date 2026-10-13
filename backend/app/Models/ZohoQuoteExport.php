<?php

namespace App\Models;

use App\Casts\EncryptedSafe;
use Illuminate\Database\Eloquent\Model;

class ZohoQuoteExport extends Model
{
    protected $fillable = ['scope', 'source_type', 'source_id', 'client_id', 'project_id', 'customer_id', 'reference', 'estimate_id', 'invoice_id', 'state', 'conversion_state', 'converted_at', 'payload'];
    protected $hidden = ['payload'];
    protected $casts = ['payload' => EncryptedSafe::class, 'converted_at' => 'datetime'];
}
