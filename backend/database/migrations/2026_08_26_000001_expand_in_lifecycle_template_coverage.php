<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ProjectController::createProjectWithCodes() is switching from a hardcoded
 * per-service stage list (stagesForServiceCode()) to the DB-driven
 * JurisdictionLifecycleTemplate/Stage system for every new project, so that
 * docket events can drive stage completion via gate_criteria (see the
 * 2026_08_26_000002 migration and StageAdvancementService).
 *
 * 2026_07_19_000000_create_service_lifecycle_and_transition_rules.php only
 * templated 8 of the ~35 India service codes stagesForServiceCode() handles.
 * This migration ports the remaining ones verbatim from that method's
 * hardcoded stage-name arrays (kept intact in ProjectController as a
 * fallback safety net — this is additive reference data, not a rewrite of
 * any existing project's data).
 */
return new class extends Migration
{
    public function up(): void
    {
        $templates = [
            'PAS' => ['India patentability/prior-art search', ['Matter Created', 'Inventor / Technology Disclosure Requested', 'Disclosure Received', 'Search Parameters Defined', 'Prior Art Search In Progress', 'Search Report Drafted', 'Search Report Reviewed Internally', 'Search Report Shared with Client']],
            'SRH' => ['India prior-art search', ['Matter Created', 'Inventor / Technology Disclosure Requested', 'Disclosure Received', 'Search Parameters Defined', 'Prior Art Search In Progress', 'Search Report Drafted', 'Search Report Reviewed Internally', 'Search Report Shared with Client']],
            'PAT' => ['India patentability search', ['Matter Created', 'Inventor / Technology Disclosure Requested', 'Disclosure Received', 'Search Parameters Defined', 'Prior Art Search In Progress', 'Search Report Drafted', 'Search Report Reviewed Internally', 'Search Report Shared with Client']],
            'FTO' => ['India freedom-to-operate search', ['Matter Created', 'Inventor / Technology Disclosure Requested', 'Disclosure Received', 'Search Parameters Defined', 'Prior Art Search In Progress', 'Search Report Drafted', 'Search Report Reviewed Internally', 'Search Report Shared with Client']],
            'CPE' => ['India complete patent filing (convention/PCT-adjacent)', ['Matter Created', 'Inventor Disclosure Reviewed', 'Claims Drafted', 'Claims Shared with Client', 'Claims Approved by Client', 'Specification Drafting Started', 'Draft Completed', 'Internal Review', 'Corrections Incorporated', 'Partner Review', 'Draft Shared with Client', 'Client Feedback Received', 'Revised Draft Completed', 'Forms Prepared (Form 1, 2, 3)', 'Government Fees Paid', 'Filed with IPO', 'Completed — Awaiting Publication']],
            'CVP' => ['India convention filing', ['Matter Created', 'Priority Application Documents Received', 'Priority Date Verified', '12-Month Deadline Confirmed', 'Claims Drafted (adapted for Indian law)', 'Specification Drafted', 'Internal Review', 'Partner Review', 'Client Approval', 'Forms Prepared (Form 1, 2, 3, 4 — Priority)', 'Filed with IPO (within 12 months of priority)', 'Completed — Awaiting Publication']],
            'PCT' => ['India PCT international filing', ['Matter Created', 'Priority Date Verified', 'International Application Drafted', 'Receiving Office Selected (RO/IN or others)', 'International Fees Calculated', 'Application Filed at Receiving Office', 'Filing Receipt / IB Reference Received', 'International Search Report (ISR) Received', 'Written Opinion Received', 'Chapter II Examination (Optional)', 'Client Review of ISR / Written Opinion', 'National Phase Entry Deadline Set (India: 31 months from priority)', 'International Publication Confirmed (18 months)', 'Completed — National Phase Entry Pending']],
            'NAP' => ['India national phase entry', ['Matter Created', 'PCT Application Documents Received', '31-Month National Phase Deadline Verified', 'National Phase Entry Decision Confirmed', 'Translation Prepared (if required)', 'National Phase Entry Application Drafted', 'Claims Adapted for Indian Law', 'Internal Review', 'Partner Review', 'Forms Prepared (Form 1, 2, 3 — National Phase)', 'Government Fees Paid', 'Filed with IPO (within 31 months)', 'Application Number Received', 'Completed — Awaiting Publication']],
            'NPE' => ['India national phase entry', ['Matter Created', 'PCT Application Documents Received', '31-Month National Phase Deadline Verified', 'National Phase Entry Decision Confirmed', 'Translation Prepared (if required)', 'National Phase Entry Application Drafted', 'Claims Adapted for Indian Law', 'Internal Review', 'Partner Review', 'Forms Prepared (Form 1, 2, 3 — National Phase)', 'Government Fees Paid', 'Filed with IPO (within 31 months)', 'Application Number Received', 'Completed — Awaiting Publication']],
            'NAF' => ['India national phase entry', ['Matter Created', 'PCT Application Documents Received', '31-Month National Phase Deadline Verified', 'National Phase Entry Decision Confirmed', 'Translation Prepared (if required)', 'National Phase Entry Application Drafted', 'Claims Adapted for Indian Law', 'Internal Review', 'Partner Review', 'Forms Prepared (Form 1, 2, 3 — National Phase)', 'Government Fees Paid', 'Filed with IPO (within 31 months)', 'Application Number Received', 'Completed — Awaiting Publication']],
            'NPA' => ['India national phase entry', ['Matter Created', 'PCT Application Documents Received', '31-Month National Phase Deadline Verified', 'National Phase Entry Decision Confirmed', 'Translation Prepared (if required)', 'National Phase Entry Application Drafted', 'Claims Adapted for Indian Law', 'Internal Review', 'Partner Review', 'Forms Prepared (Form 1, 2, 3 — National Phase)', 'Government Fees Paid', 'Filed with IPO (within 31 months)', 'Application Number Received', 'Completed — Awaiting Publication']],
            'DVA' => ['India divisional application', ['Matter Created', 'Parent Application Identified', 'Claims to Divide Identified', 'Controller Objection / Invitation Noted', 'Divisional Claims Drafted', 'Specification Prepared', 'Internal Review', 'Partner Review', 'Client Approval', 'Forms Prepared (Form 1, 2)', 'Government Fees Paid', 'Filed with IPO — Linked to Parent', 'Completed — Awaiting Publication']],
            'PAD' => ['India patent of addition', ['Matter Created', 'Parent Patent Identified', 'Improvement / Addition Defined', 'Addition Claims Drafted', 'Claims Reviewed Internally', 'Partner Review', 'Client Approval', 'Forms Prepared (Form 1, 2 — Addition)', 'Government Fees Paid', 'Filed with IPO', 'Application Number Received', 'Completed — Awaiting Publication']],
            '9EP' => ['India publication (S.11A)', ['Application Filed and Priority Date Recorded', 'Publication Date Calculated (18 months from earliest priority — S.11A)', 'Early Publication Requested (Form 9 — optional)', 'Published in Official Journal', 'Publication Number Confirmed', 'Completed — Ready for Examination Request']],
            '98A' => ['India publication (S.11A)', ['Application Filed and Priority Date Recorded', 'Publication Date Calculated (18 months from earliest priority — S.11A)', 'Early Publication Requested (Form 9 — optional)', 'Published in Official Journal', 'Publication Number Confirmed', 'Completed — Ready for Examination Request']],
            '18F' => ['India request for examination', ['Application Published (18F Trigger)', 'RFE Deadline Docketed (31 months from earliest priority; 48 months if filed before 15.03.2024)', 'Examination Request Decision Made', 'Form 18 Prepared', 'Government Fee Calculated', 'RFE Filed with IPO', 'Completed — Awaiting First Examination Report']],
            '18A' => ['India expedited request for examination', ['Application Published (18A Trigger)', 'RFE Deadline Docketed (31 months from earliest priority; 48 months if filed before 15.03.2024)', 'Grounds for Acceleration Verified (Rule 24C eligibility)', 'Examination Request Decision Made', 'Form 18A Prepared', 'Government Fee Calculated', 'RFE Filed with IPO', 'Completed — Awaiting First Examination Report (Expedited)']],
            'GRT' => ['India grant recordal', ['Grant Order Received', 'Patent Certificate Issued', 'Patent Number Recorded', 'Accumulated Renewal Fees Docketed (due 3 months from grant recordal — Rule 80(3))', 'Renewal Schedule Set (Years 3–20)', 'Form 27 Schedule Set (once every 3 financial years)', 'Completed — Patent Active']],
            'RPO' => ['India restoration of lapsed patent', ['Patent Lapse Identified (renewal fee missed — S.53)', 'Restoration Window Verified (18 months from lapse — S.60)', 'Restoration Petition Prepared (Form 15)', 'Evidence of Unintentional Lapse Compiled', 'Restoration Petition Filed', 'Controller Decision Received', 'Completed — Patent Restored or Ceased']],
            'ABN' => ['India abandonment / revival', ['Abandonment Trigger Identified (missed response deadline — S.21(1))', 'Rule 138 Extension Window Evaluated (up to 6 months)', 'Client Advised of Options', 'Extension Petition Filed / Matter Closed', 'Completed — Restored to Prosecution or Abandoned']],
            'PGO' => ['India pre-grant opposition', ['Pre-Grant Opposition Received / Filed (S.25(1))', 'Representation Analyzed', 'Reply Statement Drafted (within 2 months of notice — Rule 55(4))', 'Evidence Prepared', 'Reply Filed with IPO', 'Hearing Scheduled (if requested)', 'Hearing Attended', 'Controller Order Received', 'Completed — Application Proceeds or Refused']],
            'WDR' => ['India application withdrawal', ['Withdrawal Decision by Client', 'Pre-Publication Check (withdraw before publication to preserve secrecy — S.11B(4))', 'Withdrawal Request Prepared', 'Withdrawal Request Filed', 'Withdrawal Recorded by IPO', 'Completed — Application Withdrawn']],
            'OPP' => ['India post-grant opposition', ['Post-Grant Opposition Filed / Received (S.25(2) — within 12 months of grant publication)', 'Opposition Petition Analyzed', 'Reply Statement Drafted', 'Evidence Affidavit Prepared', 'Evidence of Opponent Received', 'Evidence Reply Prepared', 'Hearing Scheduled', 'Hearing Arguments Prepared', 'Hearing Attended', 'Order Received', 'Completed — Patent Maintained or Revoked']],
            '27F' => ['India Form 27 working statement', ['Form 27 Due Date Identified (once every 3 financial years)', 'Working Statement Prepared', 'Client Approval', 'Form 27 Filed']],
            'ROA' => ['India refusal review/appeal', ['Refusal Order Received', 'Review Petition Evaluated (S.77(1)(f) — within 1 month)', 'Appeal Decision Made (High Court — S.117A)', 'Completed — Review/Appeal Filed or Matter Closed']],
            'ERH' => ['India IPAB/High Court appeal', ['Appeal Decision Made', 'Appeal Filed at High Court (S.117A)', 'Grounds of Appeal Prepared', 'Counter-Statement by Respondent Received', 'Reply Filed', 'Oral Arguments Scheduled', 'Hearing Attended', 'Judgment / Order Received', 'Completed — Decision']],
            '24F' => ['India revocation proceeding', ['Revocation Petition Received', 'Reply Statement Prepared', 'Evidence Filed', 'Counter-Evidence Received', 'Hearing Scheduled', 'Hearing Attended', 'Order Received', 'Completed — Patent Maintained or Revoked']],
        ];

        foreach ($templates as $serviceCode => [$name, $stages]) {
            $templateId = DB::table('jurisdiction_lifecycle_templates')->insertGetId([
                'jurisdiction' => 'IN', 'service_code' => $serviceCode, 'name' => $name,
                'version' => '2026.4', 'effective_from' => '2026-01-01', 'is_active' => true,
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
    }

    public function down(): void
    {
        $templateIds = DB::table('jurisdiction_lifecycle_templates')->where('jurisdiction', 'IN')->where('version', '2026.4')->pluck('id');
        DB::table('jurisdiction_lifecycle_stages')->whereIn('jurisdiction_lifecycle_template_id', $templateIds)->delete();
        DB::table('jurisdiction_lifecycle_templates')->whereIn('id', $templateIds)->delete();
    }
};
