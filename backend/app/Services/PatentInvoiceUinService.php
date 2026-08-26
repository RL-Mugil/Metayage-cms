<?php

namespace App\Services;

use App\Models\PatentInvoiceIn;

/**
 * The firm's UIN scheme: first invoice/quote for a docket is the docket number
 * itself; the 2nd is docket/1, 3rd is docket/2, etc — counted separately per
 * type (invoice vs quote). Originally private to PatentInvoiceController;
 * extracted so RenewalActionController's renewal invoices use the exact same
 * numbering (and therefore the same Zoho Books UIN-matching) rather than a
 * second, possibly-drifting implementation.
 */
class PatentInvoiceUinService
{
    public function next(string $docketNumber, string $type, ?int $excludeId = null): string
    {
        // Postgres rejects FOR UPDATE combined with an aggregate (lockForUpdate()->count()
        // compiles to "SELECT COUNT(*) ... FOR UPDATE", which errors as
        // "FOR UPDATE is not allowed with aggregate functions"). Lock and fetch the actual
        // rows instead, then count the collection in PHP — same locking semantics, valid SQL.
        $count = PatentInvoiceIn::where('docket_number', $docketNumber)
            ->where('type', $type)
            ->when($excludeId, fn ($q) => $q->where('id', '!=', $excludeId))
            ->lockForUpdate()
            ->get(['id'])
            ->count();

        return $count === 0 ? $docketNumber : $docketNumber . '/' . $count;
    }
}
