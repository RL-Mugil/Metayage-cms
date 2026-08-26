<?php

namespace App\Console\Commands;

use App\Mail\DocketDigestMail;
use App\Models\Firm;
use App\Models\ReminderProfile;
use App\Services\DocketWorklistService;
use App\Support\FirmContext;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Throwable;

class SendDocketDigestsCommand extends Command
{
    protected $signature = 'reminders:send-docket-digests';
    protected $description = 'Send configured, idempotent docket reminder digests';

    public function handle(DocketWorklistService $worklist, FirmContext $context): int
    {
        Firm::active()->each(function (Firm $firm) use ($worklist, $context): void {
            $context->run($firm, function () use ($worklist): void {
                ReminderProfile::with('user')->where('active', true)->where('email_enabled', true)->each(function (ReminderProfile $profile) use ($worklist): void {
                    if (! $profile->user || ! $this->isDue($profile)) return;
                    $filters = ($profile->filters ?? []) + ['horizon_days' => $profile->horizon_days];
                    $items = $worklist->query($profile->user, $filters)->limit(500)->get()->map(fn ($deadline) => $worklist->serialize($deadline))->all();
                    if ($items === [] && ! $profile->send_empty) { $profile->update(['last_sent_at' => now()]); return; }
                    $counts = ['actionable' => count($items), 'overdue' => collect($items)->where('band', 'overdue')->count(),
                        'red' => collect($items)->where('band', 'red')->count(), 'amber' => collect($items)->where('band', 'amber')->count(),
                        'green' => collect($items)->where('band', 'green')->count()];
                    foreach ($profile->recipients ?? [$profile->user->email] as $recipient) $this->deliver($profile, $recipient, $items, $counts);
                    $profile->update(['last_sent_at' => now()]);
                });
            });
        });
        return self::SUCCESS;
    }

    private function isDue(ReminderProfile $profile): bool
    {
        $local = Carbon::now($profile->timezone);
        if ($local->format('H:i') < substr($profile->send_time, 0, 5)) return false;
        $last = $profile->last_sent_at?->setTimezone($profile->timezone);
        return match ($profile->frequency) {
            'weekly' => $local->isMonday() && (! $last || ! $last->isSameWeek($local)),
            'monthly' => $local->day === 1 && (! $last || ! $last->isSameMonth($local)),
            default => ! $last || ! $last->isSameDay($local),
        };
    }

    private function deliver(ReminderProfile $profile, string $recipient, array $items, array $counts): void
    {
        $period = Carbon::now($profile->timezone)->toDateString();
        $key = hash('sha256', "{$profile->id}|{$profile->frequency}|{$period}|{$recipient}");
        $payloadHash = hash('sha256', json_encode($items, JSON_THROW_ON_ERROR));
        $attemptId = DB::table('reminder_delivery_attempts')->insertOrIgnore([
            'firm_id' => $profile->firm_id, 'reminder_profile_id' => $profile->id, 'idempotency_key' => $key,
            'channel' => 'email', 'recipient' => $recipient, 'status' => 'Pending', 'payload_hash' => $payloadHash,
            'attempt_count' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        if ($attemptId === 0) return;
        try {
            Mail::to($recipient)->send(new DocketDigestMail($profile->name, $items, $counts));
            DB::table('reminder_delivery_attempts')->where('idempotency_key', $key)->update(['status' => 'Sent', 'attempt_count' => 1, 'sent_at' => now(), 'updated_at' => now()]);
        } catch (Throwable $exception) {
            DB::table('reminder_delivery_attempts')->where('idempotency_key', $key)->update(['status' => 'Failed', 'attempt_count' => 1, 'provider_response' => mb_substr($exception->getMessage(), 0, 2000), 'updated_at' => now()]);
            report($exception);
        }
    }
}
