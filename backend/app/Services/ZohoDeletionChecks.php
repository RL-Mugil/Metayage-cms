<?php

namespace App\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class ZohoDeletionChecks
{
    public const PRIORITY_CHECKS = 300;

    public function records(string $scope): Builder
    {
        return DB::table('zoho_invoices as mirror')
            ->leftJoin('zoho_snapshots as snapshot', function ($join) {
                $join->on('snapshot.scope', '=', 'mirror.scope')->on('snapshot.remote_id', '=', 'mirror.zoho_id')
                    ->whereRaw("snapshot.resource = CASE WHEN mirror.zoho_type = 'invoice' THEN 'invoices' ELSE 'estimates' END");
            })
            ->where('mirror.scope', $scope)->whereNull('mirror.deleted_at')
            ->whereIn('mirror.zoho_type', ['invoice', 'quote']);
    }

    public function due(string $scope): Builder
    {
        return $this->records($scope)->where(function ($q) {
            $q->whereNull('snapshot.detail_synced_at')
                ->orWhere('snapshot.detail_synced_at', '<', now('Asia/Kolkata')->startOfDay()->utc());
        });
    }

    public function status(string $scope): array
    {
        $total = $this->records($scope)->count();
        $due = $this->due($scope)->count();
        return ['mode' => 'api_checks', 'total' => $total, 'due' => $due,
            'checked_today' => $total - $due, 'priority_checks' => self::PRIORITY_CHECKS,
            'oldest_checked_at' => $this->records($scope)->min('snapshot.detail_synced_at')];
    }
}
