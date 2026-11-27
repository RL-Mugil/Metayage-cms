<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A designation for client portal logins with its access checkboxes. client_id null = a
 * ready-made designation every client can use (Manager, CFO, CTO, ...); otherwise one the
 * client (or the firm for them) created.
 */
class ClientDesignation extends Model
{
    protected $fillable = ['client_id', 'name', 'permissions', 'is_inventor', 'created_by_id'];

    protected $casts = ['permissions' => 'array', 'is_inventor' => 'boolean'];

    /** Ready-made designations plus this client's own. */
    public static function forClient(int $clientId)
    {
        return self::query()->where(fn ($q) => $q->whereNull('client_id')->orWhere('client_id', $clientId))
            ->orderByRaw('client_id IS NOT NULL')->orderBy('name');
    }
}
