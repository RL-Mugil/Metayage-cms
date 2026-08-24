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

// Pull Google Task completions back into IPFlow every 15 minutes. Guarded against
// overlap since it makes a Google API call per connected user — a slow/rate-limited
// run could otherwise still be going when the next tick starts, double-processing
// completions.
Schedule::command('google:sync-completions')->everyFifteenMinutes()->withoutOverlapping()->onOneServer();

// Mirror Zoho Books invoices/estimates into the local read-only cache every 30 minutes
Schedule::command('zoho:sync')->everyThirtyMinutes();
