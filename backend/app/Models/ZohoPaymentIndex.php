<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A Zoho customer payment's fields the portal reads, in plain columns: snapshot bodies are
 * encrypted, so listing payments or finding a client's advances from them means decrypting every
 * payment. Kept in step with the snapshot (saved / deleted hooks).
 */
class ZohoPaymentIndex extends Model
{
    use SoftDeletes;

    protected $table = 'zoho_payment_index';

    protected $fillable = ['scope', 'remote_id', 'customer_id', 'customer_name', 'payment_number', 'date', 'amount', 'unused_amount',
        'exchange_rate', 'currency', 'payment_mode', 'reference', 'applications', 'deleted_at'];

    protected $casts = ['date' => 'date:Y-m-d', 'amount' => 'decimal:2', 'unused_amount' => 'decimal:2', 'exchange_rate' => 'decimal:6',
        'applications' => 'array'];

    /** Refresh the index row from a customer-payment snapshot (deleted snapshot → deleted row). */
    public static function syncFrom(ZohoSnapshot $snapshot): void
    {
        if ($snapshot->resource !== 'customerpayments') {
            return;
        }
        $row = self::withTrashed()->firstOrNew(['scope' => $snapshot->scope, 'remote_id' => (string) $snapshot->remote_id]);
        $p = json_decode((string) ($snapshot->detail ?: $snapshot->summary), true) ?: [];
        if ($p !== [] && ($p['payment_id'] ?? $p['date'] ?? null) !== null) {
            $row->fill([
                'customer_id' => ($p['customer_id'] ?? '') !== '' ? (string) $p['customer_id'] : $row->customer_id,
                'customer_name' => $p['customer_name'] ?? $row->customer_name,
                'payment_number' => $p['payment_number'] ?? $row->payment_number, 'date' => $p['date'] ?? $row->date,
                'amount' => (float) ($p['amount'] ?? 0), 'unused_amount' => (float) ($p['unused_amount'] ?? 0),
                'exchange_rate' => (float) ($p['exchange_rate'] ?? 1) ?: 1.0, 'currency' => $p['currency_code'] ?? 'INR',
                'payment_mode' => $p['payment_mode'] ?? null, 'reference' => $p['reference_number'] ?? null,
                'applications' => array_map(fn ($a) => array_intersect_key($a, array_flip(['invoice_id', 'invoice_number', 'amount_applied', 'tax_amount_withheld'])),
                    $p['invoices'] ?? []),
            ]);
        }
        $row->deleted_at = $snapshot->trashed() ? now() : null;
        $row->save();
    }
}
