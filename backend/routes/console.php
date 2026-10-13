<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Send deadline reminder notifications every day at 9:00 AM
Schedule::command('reminders:send-deadlines')->dailyAt('09:00');

// Statutory docket deadline escalation (60/30/7/1/0 days + overdue) — daily at 8:30 AM
Schedule::command('docket:notify-deadlines')->dailyAt('08:30');

// User-configurable DocketTrak-style email reports; the command applies each
// profile's timezone, frequency and idempotency period itself.
Schedule::command('reminders:send-docket-digests')->everyFiveMinutes()->withoutOverlapping()->onOneServer();

// Keep the compliance read model aligned with projects, statutory docket rules,
// patent lifecycle deadlines, trademark matters, and renewal schedules.
Schedule::command('compliance:sync')->hourly()->withoutOverlapping()->onOneServer();

// Pull Google Task completions back into IPFlow every 15 minutes. Guarded against
// overlap since it makes a Google API call per connected user — a slow/rate-limited
// run could otherwise still be going when the next tick starts, double-processing
// completions.
Schedule::command('google:sync-completions')->everyFifteenMinutes()->withoutOverlapping()->onOneServer();

// Morning allowance: 1,800 reads; reserve 100 manual reads and 100 quote writes until 23:00.
Schedule::command('zoho:sync --scheduled')->dailyAt('03:00')->timezone('Asia/Kolkata')->withoutOverlapping(120)->onOneServer();
// Offset by 2 minutes so the poll never starts in the same second as the 03:00 nightly sync.
// The minute % 10 estimates/invoices alternation in PollZohoBooks is preserved (2 -> estimates, 7 -> invoices).
Schedule::command('zoho:poll')->cron('2-59/5 * * * *')->timezone('Asia/Kolkata')->withoutOverlapping(4)->onOneServer();
Schedule::command('zoho:sync --evening')->cron('*/5 23 * * *')->timezone('Asia/Kolkata')->withoutOverlapping(65)->onOneServer();

// Remove article images that no article references any more (stray uploads older than an hour).

Schedule::command('cpi:prune-images')->dailyAt('04:30')->timezone('Asia/Kolkata')->onOneServer();
