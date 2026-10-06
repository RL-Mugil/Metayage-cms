<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Replaces the seeded IN/RNF lifecycle template's stale 7-stage list ("Renewal instruction
// confirmed" -> "Service engagement closed") with the new year-by-year renewal stage list
// ("Not renewed", "Renewed till 3rd year", ..., "Renewed till 20th year") — see
// DocketRules::renewalStageNames(), which this must stay in sync with — and seeds a matching
// REN template, since real project data uses service_code 'REN' while this table (like
// several others) only ever had a template keyed 'RNF'. Without this, JurisdictionLifecycleService
// ::resolve('IN','RNF') would keep finding and using the stale seeded template instead of
// falling back to the (now-fixed) hardcoded stagesForServiceCode() list, and resolve('IN','REN')
// would keep throwing (falling back correctly, but indirectly) instead of finding a real template.
return new class extends Migration
{
    private function renewalStageNames(): array
    {
        $stages = ['Not renewed'];
        for ($year = 3; $year <= 20; $year++) {
            $suffix = ($year % 100 >= 11 && $year % 100 <= 13) ? 'th' : match ($year % 10) {
                1 => 'st', 2 => 'nd', 3 => 'rd', default => 'th',
            };
            $stages[] = "Renewed till {$year}{$suffix} year";
        }
        return $stages;
    }

    public function up(): void
    {
        $now = now();
        $stageNames = $this->renewalStageNames();

        $rnfTemplate = DB::table('jurisdiction_lifecycle_templates')
            ->where('jurisdiction', 'IN')->where('service_code', 'RNF')
            ->orderByDesc('effective_from')->first();

        if ($rnfTemplate) {
            DB::table('jurisdiction_lifecycle_stages')
                ->where('jurisdiction_lifecycle_template_id', $rnfTemplate->id)->delete();
            $this->insertStages($rnfTemplate->id, $stageNames, $now);
        }

        $renExists = DB::table('jurisdiction_lifecycle_templates')
            ->where('jurisdiction', 'IN')->where('service_code', 'REN')->exists();

        if (!$renExists) {
            $renTemplateId = DB::table('jurisdiction_lifecycle_templates')->insertGetId([
                'jurisdiction'   => 'IN',
                'service_code'   => 'REN',
                'name'           => $rnfTemplate->name ?? 'India renewal fee service',
                'version'        => $rnfTemplate->version ?? '2026.3',
                'effective_from' => $rnfTemplate->effective_from ?? $now->toDateString(),
                'effective_to'   => $rnfTemplate->effective_to ?? null,
                'is_active'      => $rnfTemplate->is_active ?? true,
                'created_at'     => $now,
                'updated_at'     => $now,
            ]);
            $this->insertStages($renTemplateId, $stageNames, $now);
        }
    }

    private function insertStages(int $templateId, array $stageNames, $now): void
    {
        foreach ($stageNames as $sequence => $stageName) {
            DB::table('jurisdiction_lifecycle_stages')->insert([
                'jurisdiction_lifecycle_template_id' => $templateId,
                'stage_code'            => 'S' . str_pad((string) ($sequence + 1), 2, '0', STR_PAD_LEFT),
                'stage_name'            => $stageName,
                'sequence_order'        => $sequence,
                'target_duration_days'  => 0,
                'gate_criteria'         => json_encode([]),
                'created_at'            => $now,
                'updated_at'            => $now,
            ]);
        }
    }

    public function down(): void
    {
        // Only the newly-added REN template is cleanly reversible — the RNF template's
        // original 7-stage content isn't preserved anywhere to restore.
        $renTemplateIds = DB::table('jurisdiction_lifecycle_templates')
            ->where('jurisdiction', 'IN')->where('service_code', 'REN')->pluck('id');
        DB::table('jurisdiction_lifecycle_stages')->whereIn('jurisdiction_lifecycle_template_id', $renTemplateIds)->delete();
        DB::table('jurisdiction_lifecycle_templates')->whereIn('id', $renTemplateIds)->delete();
    }
};
