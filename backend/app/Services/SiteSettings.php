<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Admin-editable, site-wide presentation settings stored in `system_settings`:
 * the environment badge, maintenance mode, and the public legal pages.
 */
class SiteSettings
{
    public const DEFAULT_BADGE = 'Production · v2.0';
    public const PAGE_SLUGS = ['privacy', 'terms'];

    public const MAINTENANCE_DEFAULTS = [
        'enabled' => false,
        'title'   => 'Tuning the machine',
        'tagline' => 'Serving to do better.',
        'message' => 'The portal is being serviced and upgraded so we can serve you better. We will be back shortly.',
        'contact' => 'mugilvannan@myipstrategy.com',
    ];

    public static function get(string $key, ?string $default = null): ?string
    {
        return DB::table('system_settings')->where('key', $key)->value('value') ?? $default;
    }

    public static function put(string $key, ?string $value): void
    {
        $exists = DB::table('system_settings')->where('key', $key)->exists();
        if ($exists) {
            DB::table('system_settings')->where('key', $key)->update(['value' => $value, 'updated_at' => now()]);
        } else {
            DB::table('system_settings')->insert(['key' => $key, 'value' => $value, 'created_at' => now(), 'updated_at' => now()]);
        }
        self::flush();
    }

    public static function forget(string $key): void
    {
        DB::table('system_settings')->where('key', $key)->delete();
        self::flush();
    }

    public static function updatedAt(string $key): ?string
    {
        return DB::table('system_settings')->where('key', $key)->value('updated_at');
    }

    public static function flush(): void
    {
        Cache::forget('system_settings');
        Cache::forget('system_settings_shared');
        Cache::forget('maintenance_state');
    }

    public static function badge(): string
    {
        $v = trim((string) self::get('env_badge_text', ''));

        return $v !== '' ? $v : self::DEFAULT_BADGE;
    }

    /** Cached: read on every request by the maintenance middleware. */
    public static function maintenance(): array
    {
        return Cache::remember('maintenance_state', 60, function () {
            $rows = DB::table('system_settings')
                ->whereIn('key', ['maintenance_enabled', 'maintenance_title', 'maintenance_tagline', 'maintenance_message', 'maintenance_contact'])
                ->pluck('value', 'key');

            $d = self::MAINTENANCE_DEFAULTS;
            $pick = fn (string $k) => ($rows['maintenance_'.$k] ?? '') !== '' ? $rows['maintenance_'.$k] : $d[$k];

            return [
                'enabled' => ($rows['maintenance_enabled'] ?? 'false') === 'true',
                'title'   => $pick('title'),
                'tagline' => $pick('tagline'),
                'message' => $pick('message'),
                'contact' => $pick('contact'),
            ];
        });
    }
}
