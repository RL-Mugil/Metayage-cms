<?php

namespace App\Services;

use App\Models\Client;
use Illuminate\Support\Facades\DB;

/**
 * Resolves reminder day-thresholds and escalation cadence, merging:
 *   1. A base threshold set (varies by call site — statutory deadlines vs.
 *      task/tracker/project reminders already use different base sets).
 *   2. The client's own reminder_cadence_override, if configured (e.g.
 *      Niramai's extra 1-month-before renewal reminder — see Client.php).
 *   3. Firm-wide standard escalation cadence from system_settings (the
 *      "no escalation beyond 3 months, escalate at 2 months, again at 1
 *      month" rule described on the call) as a fallback when a client has
 *      no override of their own.
 *
 * Kept as plain array merges over cheap queries — this runs once per client
 * per reminder-command pass, not per-deadline, so no caching layer needed
 * beyond what system_settings already does elsewhere (system_settings_shared).
 */
class ReminderThresholdResolver
{
    /** Renewal fee lead time by jurisdiction — time to remit funds for foreign renewals. */
    private const RENEWAL_LEAD_DAYS_INDIA = 7;
    private const RENEWAL_LEAD_DAYS_FOREIGN = 14;

    private const DEFAULT_ESCALATION_2MONTH_DAYS = 60;
    private const DEFAULT_ESCALATION_1MONTH_DAYS = 30;
    private const DEFAULT_ESCALATION_NONE_BEYOND_DAYS = 90;

    /**
     * Merge a base threshold set with a client's reminder_cadence_override.
     * Used by both NotifyDocketDeadlinesCommand (base [60,30,7,1,0]) and
     * SendDeadlineRemindersCommand (base [1,3,7]) instead of each hardcoding
     * a flat, uniform-across-all-clients constant.
     *
     * @param int[] $base
     * @return int[]
     */
    public function thresholdsFor(array $base, ?Client $client): array
    {
        $override = $client?->reminder_cadence_override ?? [];
        $override = array_values(array_filter(array_map('intval', is_array($override) ? $override : []), fn ($d) => $d >= 0));

        $merged = array_unique(array_merge($base, $override));
        rsort($merged);

        return array_values($merged);
    }

    /** Renewal-fee reminder lead time in days, by jurisdiction (foreign renewals need longer to remit funds). */
    public function renewalLeadDaysFor(?string $jurisdiction): int
    {
        return strtoupper((string) $jurisdiction) === 'IN'
            ? self::RENEWAL_LEAD_DAYS_INDIA
            : self::RENEWAL_LEAD_DAYS_FOREIGN;
    }

    /**
     * Firm-wide standard escalation cadence (system_settings, with sane
     * defaults if unset): no escalation beyond `none_beyond_days`, an
     * escalation at `at_2month_days`, another at `at_1month_days`.
     *
     * @return array{at_2month_days: int, at_1month_days: int, none_beyond_days: int}
     */
    public function escalationCadence(): array
    {
        $rows = DB::table('system_settings')
            ->whereIn('key', ['escalation_2month_days', 'escalation_1month_days', 'escalation_none_beyond_days'])
            ->pluck('value', 'key');

        return [
            'at_2month_days'   => (int) ($rows['escalation_2month_days'] ?? self::DEFAULT_ESCALATION_2MONTH_DAYS),
            'at_1month_days'   => (int) ($rows['escalation_1month_days'] ?? self::DEFAULT_ESCALATION_1MONTH_DAYS),
            'none_beyond_days' => (int) ($rows['escalation_none_beyond_days'] ?? self::DEFAULT_ESCALATION_NONE_BEYOND_DAYS),
        ];
    }
}
