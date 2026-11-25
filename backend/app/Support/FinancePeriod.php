<?php

namespace App\Support;

use App\Models\FinanceLockFlag;
use App\Models\FinancePeriodLock;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Month close: a locked month (YYYY-MM) refuses portal edits dated in it (mark paid, waivers,
 * invoice edits, advance links, matter costs). Zoho imports still apply, since Zoho is the
 * record, but each change they make in a locked month is flagged for Accounts to review.
 */
class FinancePeriod
{
    private const CACHE = 'finance_locked_months';

    /** @return array<int, string> locked months, YYYY-MM */
    public static function lockedMonths(): array
    {
        return Cache::rememberForever(self::CACHE, fn () => FinancePeriodLock::orderBy('month')->pluck('month')->all());
    }

    public static function forget(): void
    {
        Cache::forget(self::CACHE);
    }

    public static function month(CarbonInterface|string|null $date): ?string
    {
        if ($date === null || $date === '') {
            return null;
        }

        return ($date instanceof CarbonInterface ? $date : Carbon::parse($date))->format('Y-m');
    }

    public static function isLocked(CarbonInterface|string|null $date): bool
    {
        $month = self::month($date);

        return $month !== null && in_array($month, self::lockedMonths(), true);
    }

    /** 423 when the date falls in a locked month. */
    public static function assertOpen(CarbonInterface|string|null ...$dates): void
    {
        foreach ($dates as $date) {
            if (self::isLocked($date)) {
                abort(423, Carbon::parse($date)->format('F Y').' is locked (month closed). Ask a partner or super admin to unlock it first.');
            }
        }
    }

    /** Record a change an import made in a locked month (once per subject and description). */
    public static function flag(CarbonInterface|string|null $date, string $subjectType, int $subjectId, string $what, ?string $reference = null, ?int $clientId = null): void
    {
        if (! self::isLocked($date)) {
            return;
        }
        FinanceLockFlag::firstOrCreate(
            ['month' => self::month($date), 'subject_type' => $subjectType, 'subject_id' => $subjectId, 'what' => mb_substr($what, 0, 255), 'reviewed_at' => null],
            ['reference' => $reference, 'client_id' => $clientId]
        );
    }
}
