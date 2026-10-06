<?php

namespace App\Console\Commands;

use App\Services\ZohoRecordLifecycle;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\{DB, Cache};

class ProcessZohoDeletions extends Command
{
    protected $signature = 'zoho:process-deletions {--retry-failed : Replay events that exhausted local processing retries}';
    protected $description = 'Process saved Zoho deletion notifications without calling Zoho';

    public function handle(ZohoRecordLifecycle $lifecycle): int
    {
        $lock = Cache::lock('zoho_delete_worker', 120);
        if (! $lock->get()) {
            return self::SUCCESS;
        }
        try {
            if ($this->option('retry-failed')) {
                DB::transaction(function () {
                    $count = DB::table('zoho_webhook_events')->whereNull('processed_at')->where('attempts', '>=', 5)
                        ->update(['attempts' => 0, 'next_attempt_at' => null]);
                    \App\Models\AuditLog::create(['user_id' => null, 'action' => 'zoho_deletions_replayed', 'subject_type' => 'ZohoWebhook',
                        'subject_id' => null, 'metadata' => ['count' => $count]]);
                });
            }
            $events = DB::table('zoho_webhook_events')->whereNull('processed_at')->where('attempts', '<', 5)
                ->where(fn ($q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))->orderBy('id')->limit(100)->get();
            foreach ($events as $event) {
                try {
                    DB::transaction(function () use ($event, $lifecycle) {
                        $locked = DB::table('zoho_webhook_events')->where('id', $event->id)->lockForUpdate()->first();
                        if ($locked->processed_at) {
                            return;
                        }
                        $lifecycle->delete($locked->scope, $locked->resource, $locked->remote_id, 'webhook');
                        DB::table('zoho_webhook_events')->where('id', $event->id)->update(['processed_at' => now(), 'attempts' => $locked->attempts + 1]);
                    });
                } catch (\Throwable $error) {
                    report($error);
                    DB::table('zoho_webhook_events')->where('id', $event->id)->update([
                        'attempts' => DB::raw('attempts + 1'), 'next_attempt_at' => now()->addMinutes(5),
                    ]);
                }
            }
            $lifecycle->purgeDeletedPdfs();
            return DB::table('zoho_webhook_events')->whereNull('processed_at')->where('attempts', '>=', 5)->exists() ? self::FAILURE : self::SUCCESS;
        } finally {
            $lock->release();
        }
    }
}
