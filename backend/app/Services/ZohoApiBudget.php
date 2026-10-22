<?php

namespace App\Services;

use App\Exceptions\ZohoBudgetExhausted;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/** Durable reservations count failed requests too; cache clearing cannot reset the budget. */
class ZohoApiBudget
{
    public const DAILY_LIMIT = 2000;
    public const MANUAL_READ_LIMIT = 100;
    public const WRITE_LIMIT = 100;
    public const MANUAL_RESERVE = self::MANUAL_READ_LIMIT + self::WRITE_LIMIT;
    public const POLL_LIMIT = 350;
    public const NIGHTLY_LIMIT = self::DAILY_LIMIT - self::MANUAL_RESERVE - self::POLL_LIMIT;
    public const ADDON_MAX = 450;

    public function reserve(string $scope, bool $background, bool $write = false, ?string $eveningDate = null, bool $emergency = false, bool $addon = false, bool $polling = false): void
    {
        DB::transaction(function () use ($scope, $background, $write, $eveningDate, $emergency, $addon, $polling) {
            if ($eveningDate !== null) $this->assertEvening($scope, $eveningDate);
            $today = now('Asia/Kolkata')->toDateString();
            DB::table('zoho_api_budgets')->insertOrIgnore(['scope' => $scope, 'budget_date' => $today, 'calls' => 0, 'updated_at' => now()]);
            $row = DB::table('zoho_api_budgets')->where('scope', $scope)->lockForUpdate()->first();
            if ($row->blocked_until && Carbon::parse($row->blocked_until)->isFuture()) {
                throw new ZohoBudgetExhausted('Zoho has requested a cooldown. Saved progress is retained.', (int) ceil(now()->diffInSeconds(Carbon::parse($row->blocked_until))));
            }
            $calls = $row->budget_date === $today ? (int) $row->calls : 0;
            // The caller says whether this is the 5-minute poll. (It used to look the flag up on a fresh service object, which was
            // always false, so every poll was charged to the nightly pool and could be starved by the nightly sync.)
            $isPoll = $background && $polling;
            $column = $addon ? 'addon_calls' : ($emergency ? 'emergency_calls' : ($eveningDate !== null ? 'evening_calls' : ($write ? 'write_calls' : ($isPoll ? 'poll_calls' : ($background ? 'automatic_calls' : 'manual_read_calls')))));
            $limit = $addon ? min(self::ADDON_MAX, (int) $row->addon_limit) : ($emergency ? self::MANUAL_RESERVE : ($eveningDate !== null ? self::MANUAL_RESERVE : ($write ? self::WRITE_LIMIT : ($isPoll ? self::POLL_LIMIT : ($background ? self::NIGHTLY_LIMIT : self::MANUAL_READ_LIMIT)))));
            $used = $row->budget_date === $today ? (int) $row->{$column} : 0;
            if ($calls >= ($addon ? self::DAILY_LIMIT + $limit : self::DAILY_LIMIT) || $used >= $limit) {
                $reason = $addon ? 'addon_reserve' : ($emergency ? 'emergency_reserve' : ($background && ! $write && $eveningDate === null && $used >= self::NIGHTLY_LIMIT ? 'automatic_daily' : 'daily_budget'));
                throw new ZohoBudgetExhausted('The daily Zoho allowance for this operation has been reached. Saved progress is retained; try after midnight IST.', (int) ceil(now()->diffInSeconds(now('Asia/Kolkata')->addDay()->startOfDay())), $reason);
            }
            if ($row->last_call_at) {
                $delay = 0.8 - Carbon::parse($row->last_call_at)->diffInSeconds(now(), false);
                if ($delay > 0 && ! app()->runningUnitTests()) usleep((int) (min($delay, 0.8) * 1000000));
            }
            $today = now('Asia/Kolkata')->toDateString();
            if ($eveningDate !== null) $this->assertEvening($scope, $eveningDate);
            $calls = $row->budget_date === $today ? (int) $row->calls : 0;
            $counts = [];
            foreach (['automatic_calls', 'poll_calls', 'manual_read_calls', 'write_calls', 'evening_calls', 'emergency_calls', 'addon_calls'] as $counter) {
                $counts[$counter] = ($row->budget_date === $today ? (int) $row->{$counter} : 0) + ($counter === $column ? 1 : 0);
            }
            DB::table('zoho_api_budgets')->where('scope', $scope)->update(['budget_date' => $today, 'calls' => $calls + 1, 'last_call_at' => now()->format('Y-m-d H:i:s.u'), 'updated_at' => now()] + $counts);
        });
    }

    public function assertEvening(string $scope, string $date): void
    {
        $now = now('Asia/Kolkata');
        if ($now->toDateString() !== $date || $now->hour !== 23 || $now->gte($now->copy()->endOfDay()->subSeconds(70))) {
            throw new ZohoBudgetExhausted('Evening continuation is outside its safe 11 PM–midnight IST window.', 0, 'evening_window');
        }
        $cycle = DB::table('zoho_sync_progress')->where('scope', $scope)->where('resource', '__cycle')->first();
        if (! $cycle || $cycle->completed_at || $cycle->evening_eligible_date !== $date) throw new ZohoBudgetExhausted('No unfinished morning run exhausted its automatic allowance today.', 0, 'not_eligible');
    }

    public function coolDown(string $scope, int $seconds): void
    {
        DB::transaction(function () use ($scope, $seconds) {
            $query = DB::table('zoho_api_budgets')->where('scope', $scope);
            $row = (clone $query)->lockForUpdate()->first();
            $until = now()->addSeconds($seconds);
            if ($row && (! $row->blocked_until || $until->gt(Carbon::parse($row->blocked_until)))) $query->update(['blocked_until' => $until->format('Y-m-d H:i:s.u')]);
        });
    }

    public function status(string $scope): array
    {
        $row = DB::table('zoho_api_budgets')->where('scope', $scope)->first();
        $used = $row && $row->budget_date === now('Asia/Kolkata')->toDateString() ? (int) $row->calls : 0;
        $pools = [];
        foreach (['automatic' => self::NIGHTLY_LIMIT, 'poll' => self::POLL_LIMIT, 'manual_read' => self::MANUAL_READ_LIMIT, 'write' => self::WRITE_LIMIT, 'emergency' => self::MANUAL_RESERVE] as $pool => $limit) {
            $count = $row && $row->budget_date === now('Asia/Kolkata')->toDateString() ? (int) $row->{$pool . '_calls'} : 0;
            $pools[$pool] = ['used' => $count, 'limit' => $limit, 'remaining' => max(0, min($limit - $count, self::DAILY_LIMIT - $used))];
        }
        $addonUsed = $row && $row->budget_date === now('Asia/Kolkata')->toDateString() ? (int) ($row->addon_calls ?? 0) : 0;
        $addonLimit = min(self::ADDON_MAX, (int) ($row->addon_limit ?? 0));
        $pools['addon'] = ['used' => $addonUsed, 'limit' => $addonLimit, 'remaining' => max(0, min($addonLimit - $addonUsed, self::DAILY_LIMIT + self::ADDON_MAX - $used))];
        return ['used' => $used, 'limit' => self::DAILY_LIMIT, 'remaining' => max(0, self::DAILY_LIMIT - $used), 'nightly_limit' => self::NIGHTLY_LIMIT, 'pools' => $pools, 'evening_used' => $row && $row->budget_date === now('Asia/Kolkata')->toDateString() ? (int) $row->evening_calls : 0, 'addon_limit' => $addonLimit, 'addon_used' => $addonUsed];
    }
}
