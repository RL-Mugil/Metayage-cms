<?php

namespace App\Services;

use App\Models\{AuditLog, ZohoInvoice, ZohoQuoteExport, ZohoSnapshot};
use Illuminate\Support\Facades\{Cache, DB, Storage};

class ZohoRecordLifecycle
{
    /** Every writer locks this identity before mutating mirror rows, including an absent row. */
    public function isDeleted(string $scope, string $resource, string $id): bool
    {
        $key = ['scope' => $scope, 'resource' => $resource, 'remote_id' => $id];
        DB::table('zoho_record_states')->insertOrIgnore($key);
        return DB::table('zoho_record_states')->where($key)->lockForUpdate()->first()->deleted_at !== null;
    }

    public function delete(string $scope, string $resource, string $id, string $source): void
    {
        if (! in_array($resource, ['invoices', 'estimates'], true)) {
            throw new \InvalidArgumentException('Unsupported deletion resource.');
        }
        DB::transaction(function () use ($scope, $resource, $id, $source) {
            if ($this->isDeleted($scope, $resource, $id)) {
                return;
            }
            DB::table('zoho_record_states')->where(['scope' => $scope, 'resource' => $resource, 'remote_id' => $id])
                ->update(['deleted_at' => now(), 'deletion_source' => $source]);
            $record = ZohoInvoice::withoutGlobalScopes()->where('scope', $scope)
                ->where('zoho_type', $resource === 'invoices' ? 'invoice' : 'quote')->where('zoho_id', $id)->lockForUpdate()->first();
            if ($record) {
                $record->delete();
                self::invalidate($record);
            }
            $snapshot = ZohoSnapshot::withTrashed()->where(['scope' => $scope, 'resource' => $resource, 'remote_id' => $id])->lockForUpdate()->first();
            if ($snapshot) {
                $snapshot->update(['summary' => '{}', 'detail' => null, 'pdf_fingerprint' => null]);
                $snapshot->delete();
            }
            if ($resource === 'estimates') {
                ZohoQuoteExport::where('scope', $scope)->where('estimate_id', $id)->update(['state' => 'deleted']);
            }
            AuditLog::create(['user_id' => auth()->id(), 'action' => 'zoho_remote_deleted', 'subject_type' => 'ZohoInvoice', 'subject_id' => $record?->id,
                'metadata' => ['scope' => $scope, 'resource' => $resource, 'remote_id' => $id, 'source' => $source],
                'ip_address' => request()->ip(), 'user_agent' => request()->userAgent()]);
        });
    }

    /** Retried by the local deletion worker. No Books API calls. */
    public function purgeDeletedPdfs(): void
    {
        foreach (ZohoSnapshot::onlyTrashed()->whereNotNull('pdf_path')->limit(100)->get() as $snapshot) {
            if (! Storage::disk('local')->exists($snapshot->pdf_path) || Storage::disk('local')->delete($snapshot->pdf_path)) {
                $snapshot->update(['pdf_path' => null]);
            }
        }
    }

    public static function cacheKey(string $scope, string $key): string
    {
        return 'zoho:' . hash('sha256', $scope) . ':' . $key;
    }

    public static function invalidate(ZohoInvoice $record): void
    {
        foreach (["zoho_client_summary_{$record->client_id}", "zoho_project_summary_{$record->project_id}"] as $key) {
            Cache::forget(self::cacheKey($record->scope, $key));
            Cache::forget($key); // Also evict caches created by the previous release.
        }
    }
}
