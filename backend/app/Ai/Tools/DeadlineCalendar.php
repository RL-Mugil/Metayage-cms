<?php

namespace App\Ai\Tools;

use App\Ai\Tool;
use App\Models\User;

class DeadlineCalendar extends Tool
{
    public function name(): string { return 'deadline_calendar'; }

    public function title(): string { return 'Deadlines as a calendar file'; }

    public function description(): string
    {
        return 'An .ics calendar file of the user\'s upcoming deadlines (docket deadlines and case hard deadlines, up to 50) with a 3-day reminder, to import into Google Calendar or Outlook. Returns a 30-minute download link.';
    }

    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => [
            'days' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 365, 'description' => 'Look-ahead window (default 90).'],
        ]];
    }

    public function readOnly(): bool
    {
        return false;
    }

    public function handle(User $user, array $args): array
    {
        $days = max(1, min(365, (int) ($args['days'] ?? 90)));
        $count = count(app(UpcomingDeadlines::class)->handle($user, ['days' => $days, 'include_overdue' => false, 'limit' => 50])['deadlines']);
        $link = $this->fileLink($user, 'ics', null, 'ics', ['days' => $days], 'myipstrategy-deadlines.ics');

        return ['deadlines' => $count, 'window_days' => $days, 'download_url' => $link['url'], 'link_expires' => $link['expires_at'], 'files' => [$link]];
    }
}
