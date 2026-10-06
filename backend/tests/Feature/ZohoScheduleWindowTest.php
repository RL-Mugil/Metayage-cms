<?php

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ZohoScheduleWindowTest extends TestCase
{
    use RefreshDatabase;

    public static function times(): array
    {
        return [
            'before IST window' => ['2026-10-05 17:25:00', false],
            '11 PM IST' => ['2026-10-05 17:30:00', true],
            'last tick IST' => ['2026-10-05 18:25:00', true],
            'midnight IST' => ['2026-10-05 18:30:00', false],
            '11 PM server UTC' => ['2026-10-05 23:00:00', false],
        ];
    }

    #[DataProvider('times')]
    public function test_evening_scheduler_runs_only_in_the_ist_window(string $utc, bool $expected): void
    {
        $this->travelTo(\Carbon\Carbon::parse($utc, 'UTC'));
        $events = app(Schedule::class)->events();
        $event = collect($events)->first(fn ($event) => str_contains($event->command ?? '', 'zoho:sync --evening'));
        $this->assertNotNull($event);
        $this->assertSame($expected, $event->isDue(app()) && $event->filtersPass(app()));
    }
}
