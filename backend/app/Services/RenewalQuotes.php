<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/** The renewal quote-first switch: draft a renewal quote N days before each annuity (off by default). */
class RenewalQuotes
{
    public const KEY = 'renewal_auto_quotes';

    /** @return array{enabled: bool, days: int} */
    public static function settings(): array
    {
        $saved = json_decode((string) DB::table('system_settings')->where('key', self::KEY)->value('value'), true) ?: [];

        return ['enabled' => (bool) ($saved['enabled'] ?? false), 'days' => max(7, min(365, (int) ($saved['days'] ?? 90)))];
    }

    public static function save(bool $enabled, int $days): array
    {
        DB::table('system_settings')->updateOrInsert(['key' => self::KEY], ['value' => json_encode(['enabled' => $enabled, 'days' => $days]), 'updated_at' => now()]);

        return self::settings();
    }
}
