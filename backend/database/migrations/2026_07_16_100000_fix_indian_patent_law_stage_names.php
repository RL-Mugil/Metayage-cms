<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Rename stage names that encoded outdated / incorrect Indian patent law:
 *  - RFE period: 31 months from earliest priority (2024 Amendment Rules), not 48 from filing
 *  - Renewals: accumulated fees due 3 months from grant recordal (Rule 80(3)), not "Year 1"
 *  - Form 27: once every 3 financial years (2024 Rules), not annual
 *  - IPAB abolished (Tribunals Reforms Act 2021) — appeals go to High Court (S.117A)
 *  - PCT national phase for India: 31 months (Rule 20(4)(i)), not 30
 *  - Publication: 18 months from earliest priority (S.11A), not filing
 *  - RPO repurposed: post-grant lapse & restoration (S.60), pre-grant abandonment is now ABN
 */
return new class extends Migration
{
    private const RENAMES = [
        // 18F / 18A
        'RFE Deadline Calculated (48 months from filing)'
            => 'RFE Deadline Docketed (31 months from earliest priority; 48 months if filed before 15.03.2024)',
        'Grounds for Acceleration Prepared'
            => 'Grounds for Acceleration Verified (Rule 24C eligibility)',
        // GRT
        'Annual Renewal Date Set (Year 1 from filing)'
            => 'Accumulated Renewal Fees Docketed (due 3 months from grant recordal — Rule 80(3))',
        'Form 27 Filing Schedule Set'
            => 'Form 27 Schedule Set (once every 3 financial years)',
        // PCT
        'National Phase Entry Deadline Set (30 months)'
            => 'National Phase Entry Deadline Set (India: 31 months from priority)',
        // 9EP / 98A
        'Application Filed and Date Recorded'
            => 'Application Filed and Priority Date Recorded',
        '18-Month Publication Date Calculated'
            => 'Publication Date Calculated (18 months from earliest priority — S.11A)',
        // HRG
        'Hearing Date Set'
            => 'Hearing Date Set (max 2 adjournments of 30 days each — Rule 129A)',
        'Written Arguments / Counter-Statement Filed'
            => 'Written Submissions Filed (within 15 days of hearing — Rule 28(7))',
        // 27F
        'Form 27 Due Date Identified'
            => 'Form 27 Due Date Identified (once every 3 financial years)',
        // ROA
        'Appeal Decision Made'
            => 'Appeal Decision Made (High Court — S.117A)',
        'Completed — Appeal Filed or Matter Closed'
            => 'Completed — Review/Appeal Filed or Matter Closed',
        // ERH
        'Notice of Appeal Filed'
            => 'Appeal Filed at High Court (S.117A)',
        'Counter-Arguments by Examiner Received'
            => 'Counter-Statement by Respondent Received',
        // OPP (now explicitly post-grant)
        'Opposition Filed / Received'
            => 'Post-Grant Opposition Filed / Received (S.25(2) — within 12 months of grant publication)',
    ];

    // RPO rows carry the old "deemed abandoned" stage names — remap to the
    // ABN (pre-grant abandonment) vocabulary since that matches their meaning.
    private const RPO_RENAMES = [
        'Abandonment Trigger Identified (missed deadline)'
            => 'Abandonment Trigger Identified (missed response deadline — S.21(1))',
        'Restoration Petition Evaluated'
            => 'Rule 138 Extension Window Evaluated (up to 6 months)',
        'Decision: Restore or Close'
            => 'Extension Petition Filed / Matter Closed',
        'Completed — Matter Closed'
            => 'Completed — Restored to Prosecution or Abandoned',
    ];

    public function up(): void
    {
        foreach (self::RENAMES as $old => $new) {
            DB::table('project_stages')->where('stage_name', $old)->update(['stage_name' => $new]);
            DB::table('tracker_rows')->where('status', $old)->update(['status' => $new]);
        }

        // ERH "Appeal Decision Made" also exists as ERH stage 1 — the ROA rename above
        // hits both; acceptable, both now reference the High Court route.

        // Old RPO matters were pre-grant "deemed abandoned" — move them to ABN
        // and remap their stage names to the ABN vocabulary.
        $rpoProjectIds = DB::table('projects')->where('service_code', 'RPO')->pluck('id');
        if ($rpoProjectIds->isNotEmpty()) {
            DB::table('projects')->whereIn('id', $rpoProjectIds)->update(['service_code' => 'ABN']);
            foreach (self::RPO_RENAMES as $old => $new) {
                DB::table('project_stages')
                    ->whereIn('project_id', $rpoProjectIds)
                    ->where('stage_name', $old)
                    ->update(['stage_name' => $new]);
            }
        }
        foreach (self::RPO_RENAMES as $old => $new) {
            DB::table('tracker_rows')->where('status', $old)->update(['status' => $new]);
        }
    }

    public function down(): void
    {
        foreach (array_flip(self::RENAMES) as $new => $old) {
            DB::table('project_stages')->where('stage_name', $new)->update(['stage_name' => $old]);
            DB::table('tracker_rows')->where('status', $new)->update(['status' => $old]);
        }
        DB::table('projects')->where('service_code', 'ABN')->update(['service_code' => 'RPO']);
        foreach (array_flip(self::RPO_RENAMES) as $new => $old) {
            DB::table('project_stages')->where('stage_name', $new)->update(['stage_name' => $old]);
            DB::table('tracker_rows')->where('status', $new)->update(['status' => $old]);
        }
    }
};
