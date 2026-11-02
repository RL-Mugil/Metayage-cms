<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * The Zoho sync's own priority: invoices and quotes linked to a project (case) that exists in the portal are
 * downloaded first, whatever the client's portal access setting says. Everything else (payments, credit notes,
 * contacts, invoices and quotes with no project yet) follows with whatever allowance is left. The daily API
 * allowance and its reservations (ZohoApiBudget) are not touched; this only changes the ORDER the allowance is spent in.
 */
class ZohoPortalPriority
{
    /** A live project: the join target for the priority test. */
    private const LIVE_PROJECT = 'EXISTS (SELECT 1 FROM projects p WHERE p.id = %s AND p.deleted_at IS NULL)';

    /**
     * SQL for ORDER BY on zoho_snapshots: 0 for the snapshot of an invoice or quote whose mirror row is linked to a
     * live project, 1 for everything else.
     */
    public function snapshotRank(string $snapshot = 'zoho_snapshots'): string
    {
        return "CASE WHEN EXISTS (SELECT 1 FROM zoho_invoices m WHERE m.scope = {$snapshot}.scope AND m.zoho_id = {$snapshot}.remote_id"
            ." AND m.deleted_at IS NULL AND m.project_id IS NOT NULL"
            ." AND m.zoho_type = CASE {$snapshot}.resource WHEN 'invoices' THEN 'invoice' WHEN 'estimates' THEN 'quote' END"
            .' AND '.sprintf(self::LIVE_PROJECT, 'm.project_id').') THEN 0 ELSE 1 END';
    }

    /** SQL for ORDER BY on the zoho_invoices mirror table (the deletion checks): 0 when linked to a live project, else 1. */
    public function mirrorRank(string $mirror = 'mirror'): string
    {
        return "CASE WHEN {$mirror}.project_id IS NOT NULL AND ".sprintf(self::LIVE_PROJECT, "{$mirror}.project_id").' THEN 0 ELSE 1 END';
    }

    /** How many invoices and quotes are in the priority set, and how many of them have not been refreshed today. */
    public function status(string $scope): array
    {
        $checks = app(ZohoDeletionChecks::class);
        $total = $checks->records($scope)->whereRaw($this->mirrorRank().' = 0')->count();
        $due = $checks->due($scope)->whereRaw($this->mirrorRank().' = 0')->count();

        return ['priority_total' => $total, 'priority_due' => $due];
    }
}
