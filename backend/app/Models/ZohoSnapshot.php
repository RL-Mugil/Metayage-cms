<?php

namespace App\Models;

use App\Casts\EncryptedSafe;
use Illuminate\Database\Eloquent\Model;

class ZohoSnapshot extends Model
{
    use \Illuminate\Database\Eloquent\SoftDeletes;
    protected $fillable = [
        'scope', 'resource', 'remote_id', 'zoho_customer_id', 'summary', 'detail', 'fingerprint',
        'detail_fingerprint', 'pdf_fingerprint', 'pdf_path', 'detail_synced_at',
    ];

    protected $hidden = ['summary', 'detail', 'pdf_path'];

    protected $casts = [
        'summary' => EncryptedSafe::class,
        'detail' => EncryptedSafe::class,
        'detail_synced_at' => 'datetime',
    ];

    /** Payments and credit notes keep their Zoho customer in plain text (the bodies are encrypted). */
    protected static function booted(): void
    {
        static::saving(function (self $snapshot) {
            if (in_array($snapshot->resource, ['customerpayments', 'creditnotes'], true) && $snapshot->isDirty(['summary', 'detail'])) {
                $snapshot->zoho_customer_id = self::customerOf($snapshot) ?? $snapshot->zoho_customer_id;
            }
        });
    }

    public static function customerOf(self $snapshot): ?string
    {
        foreach ([$snapshot->detail, $snapshot->summary] as $body) {
            $customer = (string) ((json_decode((string) $body, true) ?: [])['customer_id'] ?? '');
            if ($customer !== '') {
                return $customer;
            }
        }

        return null;
    }
}
