<?php

namespace App\Models;

use App\Casts\EncryptedSafe;
use Illuminate\Database\Eloquent\Model;

class ZohoSnapshot extends Model
{
    use \Illuminate\Database\Eloquent\SoftDeletes;
    protected $fillable = [
        'scope', 'resource', 'remote_id', 'summary', 'detail', 'fingerprint',
        'detail_fingerprint', 'pdf_fingerprint', 'pdf_path', 'detail_synced_at',
    ];

    protected $hidden = ['summary', 'detail', 'pdf_path'];

    protected $casts = [
        'summary' => EncryptedSafe::class,
        'detail' => EncryptedSafe::class,
        'detail_synced_at' => 'datetime',
    ];
}
