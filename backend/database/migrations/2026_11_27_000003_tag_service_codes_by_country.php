<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Settings → Service Codes: each code is tagged to the patent offices (country codes)
 * it applies to — `countries` on every item of system_settings.dropdown_service_codes.
 * An empty list means every country (searches, opinions, drafting, trademarks, admin).
 * The case form lists the codes for the chosen Patent Office plus the every-country ones.
 *
 * Also adds the US codes the firm uses on USPTO work but that were missing from the list
 * (labels from config/project_import_codes.php), and PGV for US post-grant review.
 */
return new class extends Migration
{
    /** Procedure codes specific to India's Patent Office. */
    private const IN = ['CPT', 'CPD', 'NPA', 'EXR', 'FER', 'SER', 'TER', 'CAR', 'OBJ', 'HRG', 'PBL', 'PGO', 'PGR', 'POG', 'POR', 'ANN', 'CDL', 'GIF', 'GIE', 'GIR', 'PVF', 'PVR'];

    /** USPTO procedure codes. */
    private const US = ['NPV', 'PRD', 'OAR', 'NFO', 'FOA', 'RRT', 'QOA', 'FMP', 'IDS', 'NOA', 'MFE', 'TRC', 'PIA', 'PAM', 'EXI', 'C46', 'IPR', 'PGV', 'XPR', 'REV', 'RCE'];

    /** Same procedure in both offices. */
    private const IN_US = ['PRV', 'PCT', 'NPE', 'DIV', 'CIP', 'CON', 'CNV', 'APL', 'APR', 'REI', 'WTH', 'LAP', 'GRT', 'REN', 'AMD'];

    /** US codes added to the list when missing: code => label. */
    private const ADD = [
        'OAR' => 'Office Action Response (US)',
        'NFO' => 'Respond to Non-Final Office Action (US)',
        'FOA' => 'Respond to Final Office Action (US)',
        'RRT' => 'Respond to Restriction / Election Requirement (US)',
        'QOA' => 'Respond to Ex parte Quayle Action (US)',
        'FMP' => 'Filing Missing Parts (US)',
        'IDS' => 'Information Disclosure Statement (US)',
        'NOA' => 'Pay Issue Fee after Notice of Allowance (US)',
        'MFE' => 'Maintenance Fee - 3.5 / 7.5 / 11.5 years (US)',
        'TRC' => 'Track One Prioritized Examination (US)',
        'PIA' => 'Non-Provisional Filing with IDS and Assignment (US)',
        'PAM' => 'Preliminary Amendment (US)',
        'EXI' => 'Examiner Interview (US)',
        'C46' => 'Add / Change Applicant (US, 37 CFR 1.46)',
        'PGV' => 'Post-Grant Review at PTAB (US)',
        'XPR' => 'Ex parte Reexamination (US)',
        'REV' => 'Petition to Revive Abandoned Application (US)',
    ];

    public static function countriesFor(string $code): array
    {
        $code = strtoupper($code);

        return match (true) {
            in_array($code, self::IN_US, true) => ['IN', 'US'],
            in_array($code, self::IN, true)    => ['IN'],
            in_array($code, self::US, true)    => ['US'],
            default                            => [],
        };
    }

    public function up(): void
    {
        $raw = DB::table('system_settings')->where('key', 'dropdown_service_codes')->value('value');
        $items = $raw ? (json_decode($raw, true) ?: []) : [];
        $have = array_map(fn ($item) => strtoupper((string) $item['code']), $items);

        foreach (self::ADD as $code => $label) {
            if (! in_array($code, $have, true)) {
                $items[] = ['code' => $code, 'label' => "{$code} – {$label}"];
            }
        }
        foreach ($items as &$item) {
            // Keep tags an admin already set (re-running never overwrites them).
            $item['countries'] = $item['countries'] ?? self::countriesFor((string) $item['code']);
        }
        unset($item);

        DB::table('system_settings')->updateOrInsert(
            ['key' => 'dropdown_service_codes'],
            ['value' => json_encode(array_values($items)), 'updated_at' => now()]
        );
        Cache::forget('system_settings_shared');
    }

    public function down(): void
    {
        $raw = DB::table('system_settings')->where('key', 'dropdown_service_codes')->value('value');
        if (! $raw) {
            return;
        }
        $items = array_map(function (array $item) {
            unset($item['countries']);

            return $item;
        }, json_decode($raw, true) ?: []);
        DB::table('system_settings')->where('key', 'dropdown_service_codes')->update(['value' => json_encode($items)]);
        Cache::forget('system_settings_shared');
    }
};
