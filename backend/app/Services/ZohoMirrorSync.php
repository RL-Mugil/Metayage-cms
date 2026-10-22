<?php

namespace App\Services;

use App\Exceptions\ZohoBudgetExhausted;
use App\Models\AuditLog;
use App\Models\ZohoInvoice;
use App\Models\ZohoSnapshot;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class ZohoMirrorSync
{
    private ?\Illuminate\Support\Collection $uinIndex = null;
    private ?\Illuminate\Support\Collection $docketIndex = null;
    private ?\Illuminate\Support\Collection $docketPrefixIndex = null;
    private ?\Illuminate\Support\Collection $zohoClientIndex = null;
    private ?\Illuminate\Support\Collection $clientProjectIndex = null;
    private float $startedAt = 0;
    public function __construct(
        private ZohoBooksService $zoho,
        private ZohoCaseMatcher $matcher,
        private ZohoInvoiceMirror $mirror,
        private ZohoOpeningBalanceMirror $openingBalances,
    ) {}

    public function run(?ZohoInvoice $only = null, bool $evening = false, bool $scheduled = false, bool $emergency = false, bool $addon = false): array
    {
        if (! $this->zoho->isConfigured()) {
            return ['ok' => false, 'status' => 'unconfigured', 'message' => 'Zoho Books is not configured.'];
        }
        if ($only) {
            // Signal before the shared sync lock: an evening runner owns that lock
            // and should yield before its next request so the user can retry.
            Cache::put('zoho_manual_waiting:' . $this->zoho->scope(), true, 5);
        }
        $lock = Cache::lock('zoho_sync:' . $this->zoho->scope(), 7200);
        if (! $lock->get()) {
            return ['ok' => false, 'status' => 'busy', 'message' => 'A Zoho sync is already running. Try again shortly.'];
        }
        $this->zoho->background = $only === null;
        $this->zoho->emergency = $emergency;
        $this->zoho->addon = $addon;
        $runDate = now('Asia/Kolkata')->toDateString();
        $this->zoho->eveningDate = $evening ? $runDate : null;
        $this->startedAt = microtime(true);
        try {
            if ($evening) {
                app(ZohoApiBudget::class)->assertEvening($this->zoho->scope(), $runDate);
            }
            if ($only) {
                $resource = $only->zoho_type === 'invoice' ? 'invoices' : 'estimates';
                $record = $this->readDetail($resource, $only->zoho_id, true);
                if ($record === null) {
                    return $this->result('ok', 'Deleted in Zoho; removed from the portal mirror.');
                }
                DB::transaction(function () use ($resource, $record) {
                    $snapshot = $this->remember($resource, $record);
                    if ($snapshot) {
                        $this->saveDetail($snapshot, $record);
                    }
                });
                // Exactly one Books data request per manual refresh. PDFs wait for the nightly run.
            } else {
                DB::table('zoho_sync_progress')->insertOrIgnore(['scope' => $this->zoho->scope(), 'resource' => '__cycle', 'started_at' => now()]);
                if (! $evening) {
                    $cycleKey = ['scope' => $this->zoho->scope(), 'resource' => '__cycle'];
                    $cycle = DB::table('zoho_sync_progress')->where($cycleKey)->first();
                    $dayStart = now('Asia/Kolkata')->startOfDay()->utc();
                    $cycleCompletedBeforeToday = ! $cycle?->completed_at || \Carbon\Carbon::parse($cycle->completed_at)->lt($dayStart);
                    $erroredWithCompletedCursors = $cycle?->pause_reason === 'error'
                        && ! DB::table('zoho_sync_progress')->where('scope', $this->zoho->scope())
                            ->where('resource', '!=', '__cycle')->where(function ($q) {
                                $q->whereNull('completed_at')->orWhere('next_page', '>', 1);
                            })->exists();
                    if ($cycleCompletedBeforeToday && ($erroredWithCompletedCursors || ($cycle?->completed_at && \Carbon\Carbon::parse($cycle->completed_at)->lt($dayStart)))) {
                        DB::transaction(function () use ($cycleKey) {
                            DB::table('zoho_sync_progress')->where($cycleKey)->update([
                                'started_at' => now(), 'completed_at' => null, 'evening_eligible_date' => null, 'pause_reason' => null,
                            ]);
                            DB::table('zoho_sync_progress')->where('scope', $this->zoho->scope())
                                ->where('resource', '!=', '__cycle')
                                ->update(['next_page' => 1, 'started_at' => null, 'completed_at' => null]);
                        });
                    }
                }
                // A deleted record never appears in a list, so each invoice and quote is read from Zoho once a
                // day: the oldest-checked first, with a priority batch ahead of the lists and whatever allowance
                // is left after them. A definitive "does not exist" answer removes the record from the portal.
                $this->checkDeletions(ZohoDeletionChecks::PRIORITY_CHECKS, true);
                $this->scanCycle();
                // Emergency and temporary add-on continuations both prioritize
                // invoice/quote hydration so the financial mirror catches up
                // before spending the remaining reserve on secondary resources.
                $this->hydratePending($emergency || $addon);
                $this->checkDeletions(PHP_INT_MAX, false);
                // Finish the cycle only after details/PDFs too. A paused hydration run
                // must resume that backlog before starting another scan from page one.
                $this->rebuildOpeningBalanceMirror();
                DB::table('zoho_sync_progress')->where([
                    'scope' => $this->zoho->scope(), 'resource' => '__cycle',
                ])->update(['completed_at' => now(), 'pause_reason' => null, 'evening_eligible_date' => null]);
            }
            return $this->result('ok', $addon ? 'Zoho add-on continuation completed.' : ($emergency ? 'Emergency Zoho continuation completed.' : ($only ? 'Selected record refreshed from Zoho.' : 'Nightly Zoho sync completed.')));
        } catch (ZohoBudgetExhausted $e) {
            if (! $only && ! in_array($e->reason, ['not_eligible', 'evening_window'], true)) {
                $attrs = ['pause_reason' => $e->reason];
                if ($scheduled && ! $evening) {
                    $attrs['evening_eligible_date'] = $e->reason === 'automatic_daily' ? $runDate : null;
                }
                DB::table('zoho_sync_progress')->where('scope', $this->zoho->scope())->where('resource', '__cycle')->update($attrs);
            }
            return $this->result('paused', $e->getMessage() . ' Saved progress will resume on the next nightly run.')
                + ['retry_after' => $e->retryAfter];
        } catch (Throwable $e) {
            report($e);
            if ($addon) {
                Log::error('Zoho add-on continuation failed', [
                    'exception' => $e,
                    'message' => $e->getMessage(),
                ]);
            }
            if (! $only) {
                DB::table('zoho_sync_progress')->where('scope', $this->zoho->scope())->where('resource', '__cycle')
                    ->update(['pause_reason' => 'error', 'evening_eligible_date' => null]);
            }
            $message = $e instanceof \Illuminate\Http\Client\ConnectionException
                ? 'Zoho could not be reached or timed out. Progress saved; no automatic retry was made.'
                : ($e instanceof \Illuminate\Database\QueryException
                    ? ($addon
                        ? 'The add-on sync stopped on a database write: ' . $e->getMessage()
                        : 'The sync could not save its data. Progress is retained; check the server logs.')
                    : $e->getMessage());
            return $this->result('fail', $message);
        } finally {
            $this->zoho->background = false;
            $this->zoho->eveningDate = null;
            $this->zoho->emergency = false;
            $this->zoho->addon = false;
            $lock->release();
        }
    }

    /** Largest number of vanished records one pass will act on; more than this looks like a bad list, not deletions. */
    public const RECONCILE_MAX_REMOVALS = 25;

    /**
     * Find invoices and quotes that were deleted in Zoho Books. A deleted record never appears in the
     * "recently modified" poll, so without this it stayed in the portal (and in the receivables total)
     * until the next nightly cycle. One pass reads the full invoice and estimate lists (about 7 calls),
     * and every mirrored record that is missing from them is confirmed with a single detail read before it
     * is removed. Only a definitive "does not exist" answer from Zoho removes anything.
     *
     * @return array{ok: bool, status: string, message: string, removed: int}
     */
    public function reconcileDeleted(): array
    {
        if (! $this->zoho->isConfigured()) {
            return ['ok' => false, 'status' => 'unconfigured', 'message' => 'Zoho Books is not configured.', 'removed' => 0];
        }
        $scope = $this->zoho->scope();
        $lock = Cache::lock('zoho_sync:' . $scope, 900);
        if (! $lock->get()) {
            return ['ok' => true, 'status' => 'busy', 'message' => 'A Zoho sync is running; reconcile skipped.', 'removed' => 0];
        }
        $this->zoho->background = true;
        $this->startedAt = microtime(true);
        $passStart = now();
        $removed = 0;
        try {
            foreach (['invoices' => 'invoice', 'estimates' => 'quote'] as $resource => $type) {
                $idField = ZohoBooksService::RESOURCES[$resource][0];
                $seen = [];
                $page = 1;
                do {
                    $this->checkDuration();
                    $data = $this->zoho->page($resource, $page);
                    $more = (bool) $data['page_context']['has_more_page'];
                    if ($more && $data[$resource] === []) {
                        throw new RuntimeException('Zoho returned an empty page with more pages indicated; nothing was removed.');
                    }
                    foreach ($data[$resource] as $record) {
                        $id = (string) ($record[$idField] ?? '');
                        if ($id === '') {
                            throw new RuntimeException('Zoho returned a record without an ID; nothing was removed.');
                        }
                        $seen[$id] = true;
                    }
                    $page++;
                } while ($more);

                // Records written after the pass began (by the poll) are excluded: the list may predate them.
                $mirrored = DB::table('zoho_invoices')->where('scope', $scope)->where('zoho_type', $type)
                    ->whereNull('deleted_at')->where('synced_at', '<', $passStart)->pluck('zoho_id')->map(fn ($v) => (string) $v)->all();
                $missing = array_values(array_filter($mirrored, fn ($id) => ! isset($seen[$id])));
                if (count($missing) > self::RECONCILE_MAX_REMOVALS) {
                    $message = count($missing) . " {$resource} are missing from Zoho's list; that is too many to be deletions, so none were removed.";
                    Log::warning($message);
                    app(OpsAlertService::class)->alert('Zoho reconcile stopped', $message, 'zoho-reconcile-' . $resource, 360);
                    continue;
                }
                foreach ($missing as $id) {
                    $this->checkDuration();
                    // The detail read deletes the record only when Zoho says definitively that it does not exist.
                    if ($this->readDetail($resource, $id, true, 'reconcile_missing_from_list') === null) {
                        $removed++;
                    }
                }
            }
            if ($removed > 0) {
                return $this->result('ok', "Zoho reconcile removed {$removed} record(s) deleted in Zoho Books.") + ['removed' => $removed];
            }
            return ['ok' => true, 'status' => 'ok', 'message' => 'Zoho reconcile found nothing deleted.', 'removed' => 0];
        } catch (ZohoBudgetExhausted $e) {
            return ['ok' => true, 'status' => 'paused', 'message' => $e->getMessage(), 'removed' => $removed];
        } catch (Throwable $e) {
            report($e);
            return ['ok' => false, 'status' => 'fail', 'message' => $e->getMessage(), 'removed' => $removed];
        } finally {
            $this->zoho->background = false;
            $lock->release();
        }
    }

    /**
     * Read the invoices and quotes that have not been checked today (see ZohoDeletionChecks), oldest first, never
     * checked first. Each read refreshes the mirror row and stamps detail_synced_at, so the check resumes from the
     * next unchecked record after a pause. With $mustFinish the budget running out pauses the sync (the priority
     * batch); without it the pass just stops quietly, because it only uses what the lists left over.
     */
    /** Calls of the nightly pool the spare pass must leave unused (hourly reconcile, add-ons, manual follow-up). */
    private const SPARE_CHECK_HEADROOM = 250;

    private function checkDeletions(int $limit, bool $mustFinish): void
    {
        $scope = $this->zoho->scope();
        $due = app(ZohoDeletionChecks::class)->due($scope)
            ->orderByRaw('snapshot.detail_synced_at ASC NULLS FIRST')->orderBy('mirror.id')
            ->limit($limit)->get(['mirror.zoho_id', 'mirror.zoho_type']);

        $checked = 0;
        foreach ($due as $row) {
            $this->checkDuration();
            // The spare pass only uses what is left after the lists and the hourly deleted-records check have what they need.
            if (! $mustFinish && $checked++ % 20 === 0 && app(ZohoApiBudget::class)->status($scope)['pools']['automatic']['used'] >= ZohoApiBudget::NIGHTLY_LIMIT - self::SPARE_CHECK_HEADROOM) {
                return;
            }
            $resource = $row->zoho_type === 'invoice' ? 'invoices' : 'estimates';
            try {
                $record = $this->readDetail($resource, (string) $row->zoho_id, true, 'api_check');
            } catch (ZohoBudgetExhausted $e) {
                if ($mustFinish || ! in_array($e->reason, ['automatic_daily', 'daily_budget', 'emergency_reserve', 'addon_reserve'], true)) {
                    throw $e;
                }

                return;
            }
            if ($record === null) {
                continue; // Zoho confirmed it is gone; the lifecycle already removed it from the portal
            }
            DB::transaction(function () use ($scope, $resource, $record, $row) {
                $snapshot = ZohoSnapshot::where(['scope' => $scope, 'resource' => $resource, 'remote_id' => (string) $row->zoho_id])->first()
                    ?? $this->remember($resource, $record); // legacy mirror row that has no snapshot yet
                if ($snapshot) {
                    $this->saveDetail($snapshot, $record);
                }
            });
        }
    }

    private function hydratePending(bool $emergency = false): void
    {
        // Oldest incomplete records first so the historical backlog makes progress.
        $failures = 0;
        $pending = $this->pending();
        if ($emergency) {
            $pending->orderByRaw("CASE resource WHEN 'invoices' THEN 0 WHEN 'estimates' THEN 1 WHEN 'customerpayments' THEN 2 WHEN 'creditnotes' THEN 3 ELSE 4 END");
        }
        foreach ($pending->orderByRaw('detail_synced_at ASC NULLS FIRST')->orderBy('id')->cursor() as $snapshot) {
            $this->checkDuration();
            try {
                $this->hydrate($snapshot);
            } catch (ZohoBudgetExhausted $e) {
                throw $e;
            } catch (Throwable $e) {
                if (! ($e instanceof \App\Exceptions\ZohoApiException && $e->status === 404)) {
                    throw $e;
                }
                $failures++;
                report($e);
            }
        }
        if ($failures) {
            throw new RuntimeException("{$failures} records could not be fully downloaded; they remain pending for the next sync.");
        }
    }

    private function scanCycle(): void
    {
        $key = ['scope' => $this->zoho->scope(), 'resource' => '__cycle'];
        DB::table('zoho_sync_progress')->insertOrIgnore($key + ['started_at' => now()]);
        $cycle = DB::table('zoho_sync_progress')->where($key)->first();
        if ($cycle->completed_at) {
            if (\Carbon\Carbon::parse($cycle->completed_at)->gte(now('Asia/Kolkata')->startOfDay())) {
                return;
            }
            DB::table('zoho_sync_progress')->where($key)->update(['started_at' => now(), 'completed_at' => null]);
            $cycle = DB::table('zoho_sync_progress')->where($key)->first();
        }
        foreach (array_keys(ZohoBooksService::RESOURCES) as $resource) {
            $this->scan($resource, $cycle->started_at);
        }
    }

    private function scan(string $resource, string $cycleStarted): void
    {
        $key = ['scope' => $this->zoho->scope(), 'resource' => $resource];
        DB::table('zoho_sync_progress')->insertOrIgnore($key + ['next_page' => 1]);
        $progress = DB::table('zoho_sync_progress')->where($key)->first();
        if ($progress->completed_at && $progress->completed_at >= $cycleStarted) {
            return;
        }
        $page = (int) $progress->next_page;
        do {
            $this->checkDuration();
            $data = $this->zoho->page($resource, $page);
            $more = (bool) $data['page_context']['has_more_page'];
            if ($more && $data[$resource] === []) {
                throw new RuntimeException('Zoho returned an empty page with more pages indicated. Progress retained.');
            }
            DB::transaction(function () use ($data, $resource, $key, $page, $more) {
                foreach ($data[$resource] as $record) {
                    $this->remember($resource, $record);
                    $this->mirrorRecord($resource, $record);
                }
                DB::table('zoho_sync_progress')->where($key)->update([
                    'next_page' => $more ? $page + 1 : 1,
                    'completed_at' => $more ? null : now(),
                ]);
            });
            $page++;
        } while ($more);
    }

    private function remember(string $resource, array $record): ?ZohoSnapshot
    {
        $id = (string) ($record[ZohoBooksService::RESOURCES[$resource][0]] ?? '');
        if ($id === '') {
            throw new RuntimeException('Zoho returned a record without an ID. Progress retained.');
        }
        $json = json_encode($record, JSON_THROW_ON_ERROR);
        if (app(ZohoRecordLifecycle::class)->isDeleted($this->zoho->scope(), $resource, $id)) {
            return null;
        }
        $snapshot = ZohoSnapshot::updateOrCreate([
            'scope' => $this->zoho->scope(), 'resource' => $resource, 'remote_id' => $id,
        ], ['summary' => $json, 'fingerprint' => hash('sha256', $json)]);
        $this->audit($snapshot);
        return $snapshot;
    }

    public function pending()
    {
        return ZohoSnapshot::where('scope', $this->zoho->scope())->where(function ($q) {
            $q->whereNull('detail_fingerprint')->orWhereColumn('detail_fingerprint', '!=', 'fingerprint')
                ->orWhere('detail_synced_at', '<', now()->subDays(7))
                ->orWhere(function ($pdf) {
                    $pdf->whereIn('resource', ['invoices', 'estimates', 'creditnotes'])->where(function ($p) {
                        $p->whereNull('pdf_fingerprint')->orWhereColumn('pdf_fingerprint', '!=', 'fingerprint');
                    });
                });
        });
    }

    private function hydrate(ZohoSnapshot $snapshot): void
    {
        if (! ZohoSnapshot::whereKey($snapshot->id)->exists()) {
            return;
        }
        if ($snapshot->detail_fingerprint !== $snapshot->fingerprint || ! $snapshot->detail_synced_at
            || $snapshot->detail_synced_at->lt(now()->subDays(7))) {
            $record = $this->readDetail($snapshot->resource, $snapshot->remote_id);
            if ($record === null) {
                return;
            }
            DB::transaction(fn () => $this->saveDetail($snapshot, $record));
        }
        if (! ZohoSnapshot::whereKey($snapshot->id)->exists()) {
            return;
        }
        if (in_array($snapshot->resource, ['invoices', 'estimates', 'creditnotes'], true)
            && $snapshot->pdf_fingerprint !== $snapshot->fingerprint) {
            $pdf = $this->zoho->pdf($snapshot->resource, $snapshot->remote_id);
            $path = 'zoho/' . hash('sha256', $snapshot->scope) . '/' . $snapshot->id . '.pdf.encrypted';
            DB::transaction(function () use ($snapshot, $path, $pdf) {
                if (app(ZohoRecordLifecycle::class)->isDeleted($snapshot->scope, $snapshot->resource, $snapshot->remote_id)) {
                    return;
                }
                if (! Storage::disk('local')->put($path, encrypt($pdf))) {
                    throw new RuntimeException('Could not save the Zoho PDF.');
                }
                $snapshot->update(['pdf_path' => $path, 'pdf_fingerprint' => $snapshot->fingerprint]);
                $this->audit($snapshot);
            });
        }
    }

    private function saveDetail(ZohoSnapshot $snapshot, array $record): void
    {
        if (app(ZohoRecordLifecycle::class)->isDeleted($snapshot->scope, $snapshot->resource, $snapshot->remote_id)) {
            return;
        }
        $detail = json_encode($record, JSON_THROW_ON_ERROR);
        $snapshot->update([
            'detail' => $detail, 'detail_fingerprint' => $snapshot->fingerprint, 'detail_synced_at' => now(),
            'pdf_fingerprint' => $snapshot->detail !== $detail ? null : $snapshot->pdf_fingerprint,
        ]);
            $this->mirrorRecord($snapshot->resource, $record);
            if ($snapshot->resource === 'contacts') {
                $this->openingBalances->save($this->zoho->scope(), $record);
            }
            $this->audit($snapshot);
    }

    private function readDetail(string $resource, string $id, bool $removeMissing = false, string $source = 'manual_detail_not_found'): ?array
    {
        try {
            return $this->zoho->detail($resource, $id);
        } catch (\App\Exceptions\ZohoRecordMissing) {
            if ($removeMissing) {
                app(ZohoRecordLifecycle::class)->delete($this->zoho->scope(), $resource, $id, $source);
            }
            return null;
        }
    }

    public function syncRemote(string $resource, string $remoteId): void
    {
        abort_unless(in_array($resource, ['invoices', 'estimates'], true), 422, 'Unsupported Zoho polling resource.');
        $record = $this->readDetail($resource, $remoteId, true);
        if ($record === null) return;
        DB::transaction(function () use ($resource, $record): void {
            $snapshot = $this->remember($resource, $record);
            if ($snapshot) $this->saveDetail($snapshot, $record);
        });
    }

    private function mirrorRecord(string $resource, array $record): void
    {
        if (! in_array($resource, ['invoices', 'estimates'], true)) {
            return;
        }
        $matched = $unmatched = 0;
        $this->mirror->save($record, $resource === 'invoices' ? 'invoice' : 'quote',
            ZohoBooksService::RESOURCES[$resource][0], $resource === 'invoices' ? 'invoice_number' : 'estimate_number',
            $this->matcher, $this->uinIndex ??= $this->matcher->globalUinIndex(),
            $this->docketIndex ??= $this->matcher->globalDocketIndex(), $matched, $unmatched,
            $this->docketPrefixIndex ??= $this->matcher->globalDocketPrefixIndex(),
            $this->zohoClientIndex ??= $this->matcher->zohoClientIndex(),
            $this->clientProjectIndex ??= $this->matcher->clientProjectIndex());
    }

    /** Rebuild the derived opening-balance table from encrypted local contact snapshots. */
    private function rebuildOpeningBalanceMirror(): void
    {
        ZohoSnapshot::where('scope', $this->zoho->scope())
            ->where('resource', 'contacts')
            ->whereNotNull('detail')
            ->cursor()
            ->each(function (ZohoSnapshot $snapshot): void {
                $detail = json_decode((string) $snapshot->detail, true);
                if (is_array($detail)) {
                    $this->openingBalances->save($this->zoho->scope(), $detail);
                }
            });
    }

    private function audit(ZohoSnapshot $snapshot): void
    {
        AuditLog::create(['user_id' => auth()->id(), 'action' => 'zoho_sync', 'subject_type' => 'ZohoSnapshot',
            'subject_id' => $snapshot->id, 'metadata' => ['resource' => $snapshot->resource, 'remote_id' => $snapshot->remote_id],
            'ip_address' => request()->ip(), 'user_agent' => request()->userAgent()]);
    }

    private function result(string $status, string $message): array
    {
        try {
            DB::table('integration_logs')->insert(['slug' => 'zoho', 'event_type' => 'sync', 'status' => $status,
                'summary' => mb_substr($message, 0, 250), 'payload' => null, 'created_at' => now(), 'updated_at' => now()]);
            AuditLog::create([
                'user_id' => auth()->id(), 'action' => 'zoho_sync_run', 'subject_type' => 'ZohoSync',
                'metadata' => ['status' => $status, 'message' => $message, 'scope' => $this->zoho->scope(),
                    'pending_snapshots' => $this->pending()->count()],
                'ip_address' => request()->ip(), 'user_agent' => request()->userAgent(),
            ]);
        } catch (Throwable $e) {
            // A secondary log failure must not replace the original sync outcome.
            report($e);
        }
        return ['ok' => $status === 'ok', 'status' => $status, 'message' => $message];
    }

    private function checkDuration(): void
    {
        if (microtime(true) - $this->startedAt > 3600) {
            throw new ZohoBudgetExhausted('Sync paused after 60 minutes to avoid overlapping runs.');
        }
    }
}
