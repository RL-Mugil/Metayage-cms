<?php

namespace App\Console\Commands;

use App\Models\TrackerRow;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class SendDeadlineRemindersCommand extends Command
{
    protected $signature = 'reminders:send-deadlines';
    protected $description = 'Send deadline notifications to PCM, SCM, PR for upcoming delivery dates';

    // Thresholds in days
    private const THRESHOLDS = [1, 3, 7];

    public function handle(): int
    {
        $today = Carbon::today();
        $sent  = 0;

        foreach (self::THRESHOLDS as $days) {
            $targetDate = $today->copy()->addDays($days)->toDateString();

            $rows = TrackerRow::whereDate('delivery_due_date', $targetDate)
                ->whereNotNull('client_name')
                ->get();

            foreach ($rows as $row) {
                foreach (['pcm', 'scm', 'pr'] as $role) {
                    $name = trim($row->$role ?? '');
                    if (!$name) continue;

                    $user = $this->resolveUser($name);
                    if (!$user) continue;

                    // Skip if we already sent this reminder today
                    $alreadySent = DB::table('ip_notifications')
                        ->where('user_id', $user->id)
                        ->where('type', 'deadline')
                        ->whereDate('created_at', today())
                        ->whereRaw("meta->>'tracker_row_id' = ?", [(string) $row->id])
                        ->whereRaw("meta->>'days_remaining' = ?", [(string) $days])
                        ->exists();

                    if ($alreadySent) continue;

                    $docket = $row->docket_number ?? $row->client_name ?? 'Case';
                    $dueStr = Carbon::parse($row->delivery_due_date)->format('d M Y');

                    DB::table('ip_notifications')->insert([
                        'user_id'     => $user->id,
                        'type'        => 'deadline',
                        'title'       => $days === 1
                            ? "Due Tomorrow: {$docket}"
                            : "Due in {$days} Days: {$docket}",
                        'description' => "Delivery deadline for {$row->client_name}"
                            . ($row->docket_number ? " ({$row->docket_number})" : '')
                            . " is on {$dueStr}. You are assigned as " . strtoupper($role) . ".",
                        'meta'        => json_encode([
                            'tracker_row_id'   => $row->id,
                            'docket_number'    => $row->docket_number,
                            'client_name'      => $row->client_name,
                            'record_type'      => $row->record_type,
                            'delivery_due_date'=> $row->delivery_due_date?->toDateString(),
                            'days_remaining'   => $days,
                            'role'             => strtoupper($role),
                        ]),
                        'read_at'     => null,
                        'created_at'  => now(),
                        'updated_at'  => now(),
                    ]);
                    $sent++;
                }
            }
        }

        // Also send for overdue cases (once per day, only if not sent in last 3 days)
        $overdueRows = TrackerRow::whereNotNull('delivery_due_date')
            ->whereDate('delivery_due_date', '<', $today)
            ->get();

        foreach ($overdueRows as $row) {
            foreach (['pcm', 'scm', 'pr'] as $role) {
                $name = trim($row->$role ?? '');
                if (!$name) continue;

                $user = $this->resolveUser($name);
                if (!$user) continue;

                $alreadySent = DB::table('ip_notifications')
                    ->where('user_id', $user->id)
                    ->where('type', 'deadline')
                    ->where('created_at', '>=', now()->subDays(3))
                    ->whereRaw("meta->>'tracker_row_id' = ?", [(string) $row->id])
                    ->whereRaw("meta->>'days_remaining' = ?", ['0'])
                    ->exists();

                if ($alreadySent) continue;

                $daysLate = (int) Carbon::parse($row->delivery_due_date)->diffInDays($today);
                $docket   = $row->docket_number ?? $row->client_name ?? 'Case';

                DB::table('ip_notifications')->insert([
                    'user_id'     => $user->id,
                    'type'        => 'deadline',
                    'title'       => "OVERDUE: {$docket}",
                    'description' => "Delivery for {$row->client_name}"
                        . ($row->docket_number ? " ({$row->docket_number})" : '')
                        . " was due {$daysLate} day(s) ago. You are assigned as " . strtoupper($role) . ".",
                    'meta'        => json_encode([
                        'tracker_row_id'   => $row->id,
                        'docket_number'    => $row->docket_number,
                        'client_name'      => $row->client_name,
                        'delivery_due_date'=> $row->delivery_due_date?->toDateString(),
                        'days_remaining'   => 0,
                        'days_overdue'     => $daysLate,
                        'role'             => strtoupper($role),
                    ]),
                    'read_at'     => null,
                    'created_at'  => now(),
                    'updated_at'  => now(),
                ]);
                $sent++;
            }
        }

        $this->info("Sent {$sent} deadline notifications.");
        return 0;
    }

    private function resolveUser(string $name): ?User
    {
        $first = strtolower(explode(' ', trim($name))[0]);
        return User::whereRaw('LOWER(name) LIKE ?', ["%{$first}%"])->first();
    }
}
