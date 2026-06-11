<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Send deadline reminder notifications every day at 9:00 AM
Schedule::command('reminders:send-deadlines')->dailyAt('09:00');
