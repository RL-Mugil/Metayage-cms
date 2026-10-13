<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const VERSION = '2026.3';

    public function up(): void
    {
        foreach (config('trademark_lifecycle.templates') as $serviceCode => [$name, $stages]) {
            $exists = DB::table('jurisdiction_lifecycle_templates')
                ->where(['jurisdiction' => 'IN', 'service_code' => $serviceCode, 'version' => self::VERSION])
                ->exists();
            if ($exists) {
                continue;
            }

            $templateId = DB::table('jurisdiction_lifecycle_templates')->insertGetId([
                'jurisdiction' => 'IN', 'service_code' => $serviceCode, 'name' => $name,
                'version' => self::VERSION, 'effective_from' => '2026-01-01', 'is_active' => true,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            foreach ($stages as $sequence => $stageName) {
                DB::table('jurisdiction_lifecycle_stages')->insert([
                    'jurisdiction_lifecycle_template_id' => $templateId,
                    'stage_code' => 'S'.str_pad((string) ($sequence + 1), 2, '0', STR_PAD_LEFT),
                    'stage_name' => $stageName,
                    'sequence_order' => $sequence,
                    'target_duration_days' => 0,
                    'gate_criteria' => json_encode([]),
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }

        foreach (config('trademark_lifecycle.transitions') as [$from, $to, $description]) {
            DB::table('service_transition_rules')->updateOrInsert(
                ['jurisdiction' => 'IN', 'from_service_code' => $from, 'to_service_code' => $to],
                [
                    'required_event_type' => null, 'required_application_status' => null,
                    'description' => $description, 'is_active' => true,
                    'created_at' => now(), 'updated_at' => now(),
                ],
            );
        }
    }

    public function down(): void
    {
        $codes = array_keys(config('trademark_lifecycle.templates'));
        DB::table('service_transition_rules')->where('jurisdiction', 'IN')
            ->whereIn('from_service_code', $codes)->delete();
        DB::table('jurisdiction_lifecycle_templates')
            ->where(['jurisdiction' => 'IN', 'version' => self::VERSION])
            ->whereIn('service_code', $codes)->delete();
    }
};
