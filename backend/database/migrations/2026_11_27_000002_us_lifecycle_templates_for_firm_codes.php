<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * US (USPTO) stage lists for the service codes the firm actually picks on US cases.
 * The 2026_07_20 US templates use their own codes (OAR, ISF, M35, CNS, APP, PGR); a US
 * case coded NFO, FOA, NOA, MFE, REN, CON, APL, PRD, RRT, IDS ... fell back to the
 * generic 7-stage US list. This adds a US template per firm code, each with the stages
 * that advance on USPTO docket events, and successor rules between them.
 *
 * US post-grant review gets its own code PGV: PGR already means pre-grant opposition
 * response (IN) / pre-grant renewal (EP) in the firm's code list.
 */
return new class extends Migration
{
    private const VERSION = '2026.5';

    public function up(): void
    {
        $filing = fn (string $prepare) => ['Instructions confirmed', $prepare, 'IDS and formalities prepared', 'Attorney review', 'Client approval', 'USPTO filing', 'Filing receipt recorded'];
        $officeAction = ['Office action docketed', 'Rejections analysed', 'Amendment and arguments drafted', 'Attorney review', 'Client approval', 'Response filed', 'Awaiting USPTO action'];
        $finalAction = ['Final action docketed', 'Options advised (after-final reply, RCE, appeal, interview)', 'Amendment and arguments drafted', 'Attorney review', 'Client approval', 'Response filed', 'Advisory action / next route confirmed'];
        $maintenance = ['Window opened', 'Entity status and fee verified', 'Client authority confirmed', 'Maintenance fee paid', 'Receipt recorded', 'Grace period monitored', 'Service engagement closed'];
        $ptab = ['Petition or institution docketed', 'Party role and deadlines confirmed', 'Evidence and expert work prepared', 'Briefing filed', 'Oral hearing prepared', 'Final written decision docketed', 'Post-decision action confirmed'];

        // code => [name, stages, [sequence_order => [docket event types that complete it]]]
        $workflows = [
            'PRD' => ['US provisional drafting', ['Instructions confirmed', 'Invention disclosure reviewed', 'Provisional specification drafted', 'Drawings prepared', 'Client review', 'Ready to file / handed to filing', 'Provisional filed'], [6 => ['provisional_filed', 'us_filing_receipt']]],
            'CPT' => ['US non-provisional (from provisional)', $filing('Specification and claims drafted (claims the provisional)'), [6 => ['us_filing_receipt']]],
            'PIA' => ['US non-provisional with IDS and assignment', $filing('Specification, claims, IDS and assignment prepared'), [6 => ['us_filing_receipt']]],
            'NPE' => ['US national phase from PCT (35 USC 371)', ['National-stage instruction', '371 documents and fees prepared', 'Translation and IDS reviewed', 'Attorney review', 'Client approval', 'National-stage filing', 'Filing receipt recorded'], [6 => ['us_filing_receipt']]],
            'CON' => ['US continuation application', ['Copendency verified', 'Continuation claims prepared', 'Benefit claim reviewed', 'Attorney review', 'Client approval', 'USPTO filing', 'Filing receipt recorded'], [6 => ['us_filing_receipt']]],
            'FMP' => ['US missing parts reply', ['Notice of missing parts docketed', 'Missing items and fees identified', 'Declaration / drawings / fees prepared', 'Attorney review', 'Client approval', 'Reply filed', 'Filing receipt updated'], [0 => ['us_missing_parts']]],
            'IDS' => ['US information disclosure statement', ['References collected', 'Timing window checked (37 CFR 1.97)', 'IDS and SB/08 prepared', 'Attorney review', 'Client approval', 'IDS filed', 'Examiner consideration confirmed'], []],
            'TRC' => ['US Track One prioritized examination', ['Eligibility checked (claims, fees)', 'Request and fees prepared', 'Attorney review', 'Client approval', 'Request filed', 'Decision on request docketed', 'Examination monitored'], []],
            'RRT' => ['US restriction / election response', ['Restriction requirement docketed', 'Inventions and claim groups analysed', 'Election and traverse drafted', 'Attorney review', 'Client approval', 'Election filed', 'Awaiting USPTO action'], [0 => ['us_restriction_requirement']]],
            'OAR' => ['US office action response', $officeAction, [0 => ['us_nonfinal_office_action', 'us_final_office_action']]],
            'NFO' => ['US non-final office action response', $officeAction, [0 => ['us_nonfinal_office_action']]],
            'FOA' => ['US final office action response', $finalAction, [0 => ['us_final_office_action'], 6 => ['us_advisory_action']]],
            'QOA' => ['US Ex parte Quayle response', ['Quayle action docketed', 'Formal objections analysed', 'Corrections drafted', 'Attorney review', 'Client approval', 'Response filed', 'Awaiting allowance'], []],
            'EXI' => ['US examiner interview', ['Interview requested', 'Agenda and proposed amendments prepared', 'Attorney review', 'Client approval', 'Interview held', 'Interview summary filed', 'Next route confirmed'], []],
            'APL' => ['US PTAB ex parte appeal', ['Appealable rejection docketed', 'Notice of appeal filed', 'Appeal brief prepared', 'Examiner answer reviewed', 'Reply / oral hearing prepared', 'PTAB decision docketed', 'Next action confirmed'], [1 => ['us_appeal_notice_filed'], 5 => ['us_ptab_decision']]],
            'NOA' => ['US issue fee', ['Notice of allowance docketed', 'Issue fee and entity status verified', 'Client authority confirmed', 'Issue fee paid', 'Issue date monitored', 'Patent issued', 'Service engagement closed'], [0 => ['us_notice_of_allowance'], 3 => ['us_issue_fee_paid'], 5 => ['us_patent_issued']]],
            'MFE' => ['US maintenance fee (3.5 / 7.5 / 11.5 years)', $maintenance, [0 => ['us_maintenance_window_open']]],
            'REN' => ['US maintenance fee (3.5 / 7.5 / 11.5 years)', $maintenance, [0 => ['us_maintenance_window_open']]],
            'PGV' => ['US PTAB post-grant review', $ptab, [5 => ['us_ptab_decision']]],
        ];

        // The new OAR list replaces the 2026_07_20 one (same effective date, so retire the old).
        DB::table('jurisdiction_lifecycle_templates')->where('jurisdiction', 'US')->where('service_code', 'OAR')
            ->where('version', '2026.3')->update(['is_active' => false]);

        foreach ($workflows as $code => [$name, $stages, $gates]) {
            $id = DB::table('jurisdiction_lifecycle_templates')->insertGetId([
                'jurisdiction' => 'US', 'service_code' => $code, 'name' => $name, 'version' => self::VERSION,
                'effective_from' => '2026-01-01', 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);
            foreach ($stages as $i => $stage) {
                DB::table('jurisdiction_lifecycle_stages')->insert([
                    'jurisdiction_lifecycle_template_id' => $id,
                    'stage_code' => 'S'.str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT),
                    'stage_name' => $stage,
                    'sequence_order' => $i,
                    'target_duration_days' => 0,
                    'gate_criteria' => json_encode(isset($gates[$i]) ? ['advances_on_event_types' => $gates[$i]] : []),
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }

        // Successor engagements between the firm's US codes (same table the 2026_07_20 rules use).
        $rules = [
            ['PRD', 'NPV', null, null], ['PRV', 'CPT', null, null],  // PRV to NPV already exists (2026_07_20)
            ['NPV', 'NFO', 'us_nonfinal_office_action', null], ['CPT', 'NFO', 'us_nonfinal_office_action', null],
            ['PIA', 'NFO', 'us_nonfinal_office_action', null], ['NPE', 'NFO', 'us_nonfinal_office_action', null],
            ['CON', 'NFO', 'us_nonfinal_office_action', null],
            ['NPV', 'RRT', 'us_restriction_requirement', null], ['NPV', 'FMP', 'us_missing_parts', null],
            ['RRT', 'NFO', 'us_nonfinal_office_action', null],
            ['NFO', 'FOA', 'us_final_office_action', null], ['OAR', 'FOA', 'us_final_office_action', null],
            ['NFO', 'NOA', 'us_notice_of_allowance', null], ['FOA', 'NOA', 'us_notice_of_allowance', null],
            ['OAR', 'NOA', 'us_notice_of_allowance', null], ['QOA', 'NOA', 'us_notice_of_allowance', null],
            ['FOA', 'RCE', null, null], ['FOA', 'APL', null, null], ['FOA', 'EXI', null, null],
            ['RCE', 'NFO', 'us_nonfinal_office_action', null], ['APL', 'NOA', 'us_notice_of_allowance', null],
            ['NOA', 'MFE', 'us_patent_issued', 'Issued'],
        ];
        foreach ($rules as [$from, $to, $event, $status]) {
            DB::table('service_transition_rules')->insert([
                'jurisdiction' => 'US', 'from_service_code' => $from, 'to_service_code' => $to,
                'required_event_type' => $event, 'required_application_status' => $status,
                'description' => "US successor {$from} to {$to} (firm codes)", 'is_active' => true,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        // Cases already coded PGR on a US case meant US post-grant review.
        DB::table('projects')->where('patent_office_code', 'US')->where('service_code', 'PGR')->update(['service_code' => 'PGV']);
    }

    public function down(): void
    {
        $ids = DB::table('jurisdiction_lifecycle_templates')->where('jurisdiction', 'US')->where('version', self::VERSION)->pluck('id');
        DB::table('jurisdiction_lifecycle_stages')->whereIn('jurisdiction_lifecycle_template_id', $ids)->delete();
        DB::table('jurisdiction_lifecycle_templates')->whereIn('id', $ids)->delete();
        DB::table('service_transition_rules')->where('jurisdiction', 'US')->where('description', 'like', '%(firm codes)')->delete();
        DB::table('jurisdiction_lifecycle_templates')->where('jurisdiction', 'US')->where('service_code', 'OAR')
            ->where('version', '2026.3')->update(['is_active' => true]);
    }
};
