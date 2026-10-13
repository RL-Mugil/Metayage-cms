<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Services\ZohoApiBudget;
use App\Services\ZohoBooksService;
use App\Services\ZohoMirrorSync;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

class PollZohoBooks extends Command
{
    protected $signature = 'zoho:poll {--force : Run even when the integration is not configured}';
    protected $description = 'Poll recently changed Zoho estimates and invoices into the portal mirror';

    public function handle(ZohoBooksService $zoho, ZohoMirrorSync $mirror, ZohoApiBudget $budget): int
    {
        if (! $zoho->isConfigured()) {
            $this->warn('Zoho Books is not configured.');
            return self::SUCCESS;
        }
        $lock = Cache::lock('zoho_poll:' . $zoho->scope(), 240);
        if (! $lock->get()) {
            $this->line('A Zoho poll is already running.');
            return self::SUCCESS;
        }
        $runAt = now();
        $processed = 0;
        $failed = 0;
        $zoho->background = true;
        $zoho->polling = true;
        try {
            AuditLog::create(['user_id' => null, 'action' => 'zoho_poll_started', 'subject_type' => 'ZohoPoll',
                'metadata' => ['scope' => $zoho->scope()], 'ip_address' => null, 'user_agent' => 'scheduler']);
            // Alternate resources so five-minute polling fits the Standard-plan
            // daily API budget while the nightly resumable sync remains intact.
            $resource = now('Asia/Kolkata')->minute % 10 < 5 ? 'estimates' : 'invoices';
            foreach ([$resource] as $resource) {
                $cursor = DB::table('zoho_poll_cursors')->where(['scope' => $zoho->scope(), 'resource' => $resource])->first();
                DB::table('zoho_poll_cursors')->updateOrInsert(['scope' => $zoho->scope(), 'resource' => $resource], ['last_run_at' => $runAt, 'updated_at' => $runAt, 'created_at' => $cursor?->created_at ?? $runAt]);
                $query = ['page' => 1, 'sort_column' => 'last_modified_time', 'sort_order' => 'D'];
                $data = $zoho->listPage($resource, $query);
                // Polling is for near-real-time changes; the nightly resumable sync
                // remains responsible for historical backfill. This cap also keeps a
                // first cursor bootstrap safely below the Standard-plan daily pool.
                $records = array_slice($data[$resource] ?? [], 0, 25);
                $newest = $cursor?->last_modified_at;
                if (! $cursor?->last_modified_at && isset($records[0]['last_modified_time'])) {
                    $newest = (string) $records[0]['last_modified_time'];
                }
                foreach ($records as $record) {
                    $modified = (string) ($record['last_modified_time'] ?? $record['created_time'] ?? '');
                    if ($cursor?->last_modified_at && $modified !== '' && $modified <= $cursor->last_modified_at) {
                        continue;
                    }
                    $remoteId = (string) ($record[ZohoBooksService::RESOURCES[$resource][0]] ?? '');
                    if ($remoteId === '') continue;
                    try {
                        $mirror->syncRemote($resource, $remoteId);
                        $processed++;
                        if ($modified !== '' && ($newest === null || $modified > $newest)) $newest = $modified;
                    } catch (Throwable $error) {
                        $failed++;
                        report($error);
                    }
                }
                DB::table('zoho_poll_cursors')->where(['scope' => $zoho->scope(), 'resource' => $resource])->update([
                    'last_modified_at' => $newest, 'last_remote_id' => $records[0][ZohoBooksService::RESOURCES[$resource][0]] ?? $cursor?->last_remote_id,
                    'last_success_at' => $failed === 0 ? now() : ($cursor?->last_success_at), 'last_error' => $failed ? 'One or more records failed; see audit log.' : null, 'updated_at' => now(),
                ]);
            }
            DB::table('integration_logs')->insert(['slug' => 'zoho', 'event_type' => 'poll', 'status' => $failed ? 'partial' : 'ok',
                'summary' => "Zoho poll processed {$processed} record(s).", 'payload' => json_encode(['processed' => $processed, 'failed' => $failed]), 'created_at' => now(), 'updated_at' => now()]);
            AuditLog::create(['user_id' => null, 'action' => 'zoho_poll_completed', 'subject_type' => 'ZohoPoll',
                'metadata' => ['scope' => $zoho->scope(), 'processed' => $processed, 'failed' => $failed], 'ip_address' => null, 'user_agent' => 'scheduler']);
            return $failed ? self::FAILURE : self::SUCCESS;
        } catch (\App\Exceptions\ZohoBudgetExhausted $error) {
            if ($error->reason === \App\Exceptions\ZohoBudgetExhausted::BUSY) {
                // Another Zoho request (usually the nightly sync) is running. Yield quietly: this is
                // expected, not a failure, and the next poll runs in five minutes.
                $this->line('Zoho is busy with another request; poll skipped.');
                return self::SUCCESS;
            }
            report($error);
            DB::table('integration_logs')->insert(['slug' => 'zoho', 'event_type' => 'poll', 'status' => 'paused', 'summary' => $error->getMessage(), 'payload' => null, 'created_at' => now(), 'updated_at' => now()]);
            return self::SUCCESS;
        } catch (Throwable $error) {
            report($error);
            DB::table('integration_logs')->insert(['slug' => 'zoho', 'event_type' => 'poll', 'status' => 'fail', 'summary' => $error->getMessage(), 'payload' => null, 'created_at' => now(), 'updated_at' => now()]);
            AuditLog::create(['user_id' => null, 'action' => 'zoho_poll_failed', 'subject_type' => 'ZohoPoll', 'metadata' => ['scope' => $zoho->scope(), 'message' => $error->getMessage()], 'ip_address' => null, 'user_agent' => 'scheduler']);
            return self::FAILURE;
        } finally {
            $zoho->background = false;
            $zoho->polling = false;
            $lock->release();
        }
    }
}
