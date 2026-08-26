<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Populates jurisdiction_lifecycle_stages.gate_criteria with
 * {"advances_on_event_types": [...]} for stages that have an unambiguous
 * match against DocketRules::EVENT_TYPES — the data StageAdvancementService
 * reads to auto-complete a project's own "Prosecution lifecycle" stage when
 * a matching docket event is recorded (see DocketRules::recordEvent()).
 *
 * Only stages with a clean, single-meaning event match are set here; most
 * stages have no one docket event that proves them done and are left at
 * gate_criteria = [] (unchanged), staying on the existing manual advance
 * path (ProjectController::updateStage) exactly as before.
 *
 * Pure reference-data update — touches only template/stage rows, never any
 * project's own project_stages.
 */
return new class extends Migration
{
    public function up(): void
    {
        // [jurisdiction, service_code, sequence_order, [event_types]]
        $triggers = [
            // India — matches an existing 2026_07_19 template
            ['IN', 'PRV', 5, ['provisional_filed']],
            ['IN', 'CPT', 5, ['application_filed']],
            ['IN', 'CPD', 5, ['application_filed']],
            ['IN', 'FER', 0, ['fer_received']],
            ['IN', 'SER', 0, ['ser_received']],
            ['IN', 'TER', 0, ['ter_received']],
            ['IN', 'HRG', 0, ['hearing_notice']],
            ['IN', 'HRG', 5, ['hearing_held']],
            // India — matches a template added by 2026_08_26_000001
            ['IN', 'GRT', 0, ['granted']],
            ['IN', 'ROA', 0, ['refused']],
            ['IN', 'PGO', 0, ['opposition_notice']],
            ['IN', '18F', 0, ['published']],
            ['IN', '18A', 0, ['published']],
            ['IN', '9EP', 3, ['published']],
            ['IN', '98A', 3, ['published']],
            // US — matches the 2026_07_20 templates
            ['US', 'PRV', 6, ['us_filing_receipt']],
            ['US', 'NPV', 6, ['us_filing_receipt']],
            ['US', 'NPD', 6, ['us_filing_receipt']],
            ['US', 'NPP', 6, ['us_filing_receipt']],
            ['US', 'NPS', 6, ['us_filing_receipt']],
            ['US', 'CNS', 6, ['us_filing_receipt']],
            ['US', 'DIV', 6, ['us_filing_receipt']],
            ['US', 'CIP', 6, ['us_filing_receipt']],
            ['US', 'OAR', 0, ['us_nonfinal_office_action', 'us_final_office_action']],
            ['US', 'AFT', 0, ['us_final_office_action']],
            ['US', 'AFT', 5, ['us_advisory_action']],
            ['US', 'RCE', 5, ['us_rce_filed']],
            ['US', 'APP', 5, ['us_ptab_decision']],
            ['US', 'IPR', 5, ['us_ptab_decision']],
            ['US', 'PGR', 5, ['us_ptab_decision']],
            ['US', 'ISF', 0, ['us_notice_of_allowance']],
            ['US', 'ISF', 3, ['us_issue_fee_paid']],
            ['US', 'ISF', 5, ['us_patent_issued']],
            ['US', 'M35', 0, ['us_maintenance_window_open']],
            ['US', 'M75', 0, ['us_maintenance_window_open']],
            ['US', 'M15', 0, ['us_maintenance_window_open']],
        ];

        foreach ($triggers as [$jurisdiction, $serviceCode, $sequenceOrder, $eventTypes]) {
            $templateId = DB::table('jurisdiction_lifecycle_templates')
                ->where('jurisdiction', $jurisdiction)
                ->where('service_code', $serviceCode)
                ->where('is_active', true)
                ->value('id');

            if (! $templateId) {
                continue;
            }

            DB::table('jurisdiction_lifecycle_stages')
                ->where('jurisdiction_lifecycle_template_id', $templateId)
                ->where('sequence_order', $sequenceOrder)
                ->update(['gate_criteria' => json_encode(['advances_on_event_types' => $eventTypes])]);
        }
    }

    public function down(): void
    {
        DB::table('jurisdiction_lifecycle_stages')->update(['gate_criteria' => json_encode([])]);
    }
};
