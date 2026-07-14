<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        // Add missing service codes (INC) that appear in DocketTrak export
        $this->appendToJsonSetting('dropdown_service_codes', [
            ["code" => "INC", "label" => "INC – Incorporation / Related Recordal"],
        ], $now);

        // Add missing country/office codes that appear in DocketTrak export
        $this->appendToJsonSetting('dropdown_country_codes', [
            ["code" => "UP", "label" => "UP – Unitary Patent (UPC/EPO)"],
            ["code" => "EA", "label" => "EA – Eurasian Patent Office (EAPO)"],
            ["code" => "LA", "label" => "LA – Laos (DIP)"],
            ["code" => "IS", "label" => "IS – Iceland (IPO)"],
            ["code" => "KH", "label" => "KH – Cambodia (DIP-Cambodia)"],
            ["code" => "KP", "label" => "KP – Korea North (RPO)"],
            ["code" => "KW", "label" => "KW – Kuwait (KIPO)"],
            ["code" => "DZ", "label" => "DZ – Algeria (INAPI)"],
        ], $now);
    }

    private function appendToJsonSetting(string $key, array $toAdd, \Carbon\Carbon $now): void
    {
        $row = DB::table('system_settings')->where('key', $key)->first();
        if (! $row) {
            return;
        }

        $existing = json_decode($row->value, true) ?? [];
        $existingCodes = array_column($existing, 'code');

        foreach ($toAdd as $item) {
            if (! in_array($item['code'], $existingCodes, true)) {
                $existing[] = $item;
            }
        }

        DB::table('system_settings')
            ->where('key', $key)
            ->update(['value' => json_encode($existing), 'updated_at' => $now]);
    }

    public function down(): void
    {
        // Removals are non-destructive — skip rollback to avoid breaking existing data
    }
};
