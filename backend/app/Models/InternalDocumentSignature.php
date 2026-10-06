<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InternalDocumentSignature extends Model
{
    protected $fillable = [
        'document_type', 'document_id', 'signed_by_id', 'signer_role',
        'document_fingerprint', 'signature', 'verification_code', 'signed_at',
    ];

    protected $casts = ['signed_at' => 'datetime'];

    protected $hidden = ['signature'];

    public function signedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'signed_by_id');
    }
}
