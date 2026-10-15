<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

/**
 * Retention for operational data only. Audit logs and every business record (clients, matters, invoices,
 * payments, reminders, enquiries, bookings, Zoho data, documents) are deliberately NOT touched.
 */
class PruneOperationalData extends Command
{
    protected $signature = 'ops:prune-operational {--days=7 : Keep this many days} {--dry-run : Only count, delete nothing}';

    protected $description = 'Delete operational logs, read notifications, finished/failed job records, expired tokens and temp files older than N days';

    private const CHUNK = 5000;

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $dry = (bool) $this->option('dry-run');
        $cutoff = Carbon::now()->subDays($days);
        $ts = $cutoff->getTimestamp();

        $targets = [
            'integration_logs'       => fn ($q) => $q->where('created_at', '<', $cutoff),
            'ip_notifications'       => fn ($q) => $q->whereNotNull('read_at')->where('created_at', '<', $cutoff),
            'failed_jobs'            => fn ($q) => $q->where('failed_at', '<', $cutoff),
            'job_batches'            => fn ($q) => $q->whereNotNull('finished_at')->where('finished_at', '<', $ts),
            'personal_access_tokens' => fn ($q) => $q->whereNotNull('expires_at')->where('expires_at', '<', $cutoff),
            'sessions'               => fn ($q) => $q->where('last_activity', '<', $ts),
        ];

        $total = 0;
        foreach ($targets as $table => $scope) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            $n = $dry ? $scope(DB::table($table))->count() : $this->deleteChunked($table, $scope);
            $total += $n;
            $this->line(sprintf('%-24s %s %d', $table, $dry ? 'would delete' : 'deleted', $n));
        }

        $files = $this->pruneTempFiles($cutoff, $dry);
        $this->line(sprintf('%-24s %s %d', 'storage/app/tmp files', $dry ? 'would delete' : 'deleted', $files));

        $this->info(($dry ? 'Dry run: ' : '')."{$total} row(s) and {$files} file(s) older than {$days} day(s).");

        return self::SUCCESS;
    }

    private function deleteChunked(string $table, callable $scope): int
    {
        $deleted = 0;
        do {
            $n = DB::table($table)->whereIn('id', function ($sub) use ($table, $scope) {
                $sub->select('id')->from($table);
                $scope($sub);
                $sub->limit(self::CHUNK);
            })->delete();
            $deleted += $n;
        } while ($n === self::CHUNK);

        return $deleted;
    }

    private function pruneTempFiles(Carbon $cutoff, bool $dry): int
    {
        $dir = storage_path('app/tmp');
        if (! File::isDirectory($dir)) {
            return 0;
        }
        $count = 0;
        foreach (File::files($dir) as $file) {
            if ($file->getMTime() < $cutoff->getTimestamp()) {
                $count++;
                if (! $dry) {
                    @unlink($file->getPathname());
                }
            }
        }

        return $count;
    }
}
