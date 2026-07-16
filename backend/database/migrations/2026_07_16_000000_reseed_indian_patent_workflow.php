<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    // ── Old stage name → new stage name mapping (for progress preservation) ──
    private const STAGE_NAME_MAP = [
        'Awaiting IDF from Client'             => 'Inventor Disclosure Requested',
        'IDF Received'                         => 'Inventor Disclosure Received',
        'Drafting'                             => 'Draft Started',
        'Internal Review'                      => 'Internal Review',
        'Draft Shared with Client'             => 'Draft Shared with Client',
        'Awaiting Client Feedback'             => 'Client Feedback Received',
        'Client Comments Received'             => 'Client Feedback Received',
        'Revised Draft Shared'                 => 'Revised Draft Completed',
        'Drafted'                              => 'Revised Draft Completed',
        'Awaiting Signed Forms'                => 'Forms Prepared (Form 1, 2, 3)',
        'Filing'                               => 'Government Fees Paid',
        'Filed'                                => 'Filed with IPO',
        'Filed — Waiting for FER or Grant'    => 'Completed — Awaiting Publication',
        'Claims Ready to Share'                => 'Claims Drafted',
        'Claims Approved'                      => 'Claims Approved by Client',
        'FER Received'                         => 'Examination Report Received',
        'FER Response in Progress'             => 'Response Strategy Formulated',
        'FER Response Filed'                   => 'Response Filed (Form 3 / 13)',
        'Hearing Scheduled'                    => 'Hearing Date Set',
        'Hearing Response in Progress'         => 'Arguments Prepared',
        'Hearing Response Filed'               => 'Written Arguments / Counter-Statement Filed',
        'Granted'                              => 'Completed — Patent Active',
        'Prior Art Search'                     => 'Prior Art Search In Progress',
        'Search Report Ready'                  => 'Search Report Drafted',
        'Search Report Shared'                 => 'Search Report Shared with Client',
    ];

    private const STAGES_BY_SERVICE = [
        'PAS' => [
            "Matter Created", "Inventor / Technology Disclosure Requested", "Disclosure Received",
            "Search Parameters Defined", "Prior Art Search In Progress", "Search Report Drafted",
            "Search Report Reviewed Internally", "Search Report Shared with Client",
        ],
        'SRH' => [
            "Matter Created", "Inventor / Technology Disclosure Requested", "Disclosure Received",
            "Search Parameters Defined", "Prior Art Search In Progress", "Search Report Drafted",
            "Search Report Reviewed Internally", "Search Report Shared with Client",
        ],
        'PAT' => [
            "Matter Created", "Inventor / Technology Disclosure Requested", "Disclosure Received",
            "Search Parameters Defined", "Prior Art Search In Progress", "Search Report Drafted",
            "Search Report Reviewed Internally", "Search Report Shared with Client",
        ],
        'FTO' => [
            "Matter Created", "Inventor / Technology Disclosure Requested", "Disclosure Received",
            "Search Parameters Defined", "Prior Art Search In Progress", "Search Report Drafted",
            "Search Report Reviewed Internally", "Search Report Shared with Client",
        ],
        'PRV' => [
            "Matter Created", "Inventor Disclosure Requested", "Inventor Disclosure Received",
            "Prior Art Search (Optional)", "Draft Started", "Draft Completed", "Internal Review",
            "Corrections Incorporated", "Partner Review", "Client Review", "Client Approved",
            "Forms Prepared (Form 1, 2, 3)", "Government Fees Calculated", "Filed with IPO",
            "Application Number Received", "Completed — CPT Deadline Set (12 months)",
        ],
        'CPT' => [
            "Matter Created", "Inventor Disclosure Reviewed", "Claims Drafted",
            "Claims Shared with Client", "Claims Approved by Client", "Specification Drafting Started",
            "Draft Completed", "Internal Review", "Corrections Incorporated", "Partner Review",
            "Draft Shared with Client", "Client Feedback Received", "Revised Draft Completed",
            "Forms Prepared (Form 1, 2, 3)", "Government Fees Paid", "Filed with IPO",
            "Completed — Awaiting Publication",
        ],
        'CPE' => [
            "Matter Created", "Inventor Disclosure Reviewed", "Claims Drafted",
            "Claims Shared with Client", "Claims Approved by Client", "Specification Drafting Started",
            "Draft Completed", "Internal Review", "Corrections Incorporated", "Partner Review",
            "Draft Shared with Client", "Client Feedback Received", "Revised Draft Completed",
            "Forms Prepared (Form 1, 2, 3)", "Government Fees Paid", "Filed with IPO",
            "Completed — Awaiting Publication",
        ],
        'CPD' => [
            "Matter Created", "Inventor Disclosure Requested", "Inventor Disclosure Received",
            "Claims Drafted", "Claims Shared with Client", "Claims Approved by Client",
            "Specification Drafting Started", "Draft Completed", "Internal Review",
            "Corrections Incorporated", "Partner Review", "Draft Shared with Client",
            "Client Feedback Received", "Revised Draft Completed", "Forms Prepared (Form 1, 2, 3)",
            "Government Fees Paid", "Filed with IPO — Awaiting Publication",
        ],
        'CVP' => [
            "Matter Created", "Priority Application Documents Received", "Priority Date Verified",
            "12-Month Deadline Confirmed", "Claims Drafted (adapted for Indian law)",
            "Specification Drafted", "Internal Review", "Partner Review", "Client Approval",
            "Forms Prepared (Form 1, 2, 3, 4 — Priority)",
            "Filed with IPO (within 12 months of priority)", "Completed — Awaiting Publication",
        ],
        'PCT' => [
            "Matter Created", "Priority Date Verified", "International Application Drafted",
            "Receiving Office Selected (RO/IN or others)", "International Fees Calculated",
            "Application Filed at Receiving Office", "Filing Receipt / IB Reference Received",
            "International Search Report (ISR) Received", "Written Opinion Received",
            "Chapter II Examination (Optional)", "Client Review of ISR / Written Opinion",
            "National Phase Entry Deadline Set (30 months)",
            "International Publication Confirmed (18 months)", "Completed — National Phase Entry Pending",
        ],
        'NAP' => [
            "Matter Created", "PCT Application Documents Received",
            "31-Month National Phase Deadline Verified", "National Phase Entry Decision Confirmed",
            "Translation Prepared (if required)", "National Phase Entry Application Drafted",
            "Claims Adapted for Indian Law", "Internal Review", "Partner Review",
            "Forms Prepared (Form 1, 2, 3 — National Phase)", "Government Fees Paid",
            "Filed with IPO (within 31 months)", "Application Number Received",
            "Completed — Awaiting Publication",
        ],
        'NPE' => [
            "Matter Created", "PCT Application Documents Received",
            "31-Month National Phase Deadline Verified", "National Phase Entry Decision Confirmed",
            "Translation Prepared (if required)", "National Phase Entry Application Drafted",
            "Claims Adapted for Indian Law", "Internal Review", "Partner Review",
            "Forms Prepared (Form 1, 2, 3 — National Phase)", "Government Fees Paid",
            "Filed with IPO (within 31 months)", "Application Number Received",
            "Completed — Awaiting Publication",
        ],
        'NAF' => [
            "Matter Created", "PCT Application Documents Received",
            "31-Month National Phase Deadline Verified", "National Phase Entry Decision Confirmed",
            "Translation Prepared (if required)", "National Phase Entry Application Drafted",
            "Claims Adapted for Indian Law", "Internal Review", "Partner Review",
            "Forms Prepared (Form 1, 2, 3 — National Phase)", "Government Fees Paid",
            "Filed with IPO (within 31 months)", "Application Number Received",
            "Completed — Awaiting Publication",
        ],
        'NPA' => [
            "Matter Created", "PCT Application Documents Received",
            "31-Month National Phase Deadline Verified", "National Phase Entry Decision Confirmed",
            "Translation Prepared (if required)", "National Phase Entry Application Drafted",
            "Claims Adapted for Indian Law", "Internal Review", "Partner Review",
            "Forms Prepared (Form 1, 2, 3 — National Phase)", "Government Fees Paid",
            "Filed with IPO (within 31 months)", "Application Number Received",
            "Completed — Awaiting Publication",
        ],
        'DVA' => [
            "Matter Created", "Parent Application Identified", "Claims to Divide Identified",
            "Controller Objection / Invitation Noted", "Divisional Claims Drafted",
            "Specification Prepared", "Internal Review", "Partner Review", "Client Approval",
            "Forms Prepared (Form 1, 2)", "Government Fees Paid", "Filed with IPO — Linked to Parent",
            "Completed — Awaiting Publication",
        ],
        'PAD' => [
            "Matter Created", "Parent Patent Identified", "Improvement / Addition Defined",
            "Addition Claims Drafted", "Claims Reviewed Internally", "Partner Review", "Client Approval",
            "Forms Prepared (Form 1, 2 — Addition)", "Government Fees Paid", "Filed with IPO",
            "Application Number Received", "Completed — Awaiting Publication",
        ],
        '9EP' => [
            "Application Filed and Date Recorded", "18-Month Publication Date Calculated",
            "Published in Official Journal", "Publication Number Confirmed",
            "Completed — Ready for Examination Request",
        ],
        '98A' => [
            "Application Filed and Date Recorded", "18-Month Publication Date Calculated",
            "Published in Official Journal", "Publication Number Confirmed",
            "Completed — Ready for Examination Request",
        ],
        '18F' => [
            "Application Published (18F Trigger)", "RFE Deadline Calculated (48 months from filing)",
            "Examination Request Decision Made", "Form 18 Prepared", "Government Fee Calculated",
            "RFE Filed with IPO", "Completed — Awaiting First Examination Report",
        ],
        '18A' => [
            "Application Published (18A Trigger)", "RFE Deadline Calculated (48 months from filing)",
            "Grounds for Acceleration Prepared", "Examination Request Decision Made",
            "Form 18A Prepared", "Government Fee Calculated", "RFE Filed with IPO",
            "Completed — Awaiting First Examination Report (Expedited)",
        ],
        'FER' => [
            "Examination Report Received", "Objections Analyzed", "Response Strategy Formulated",
            "Claims Amended / Arguments Drafted", "Internal Review", "Partner Review",
            "Client Communicated", "Response Filed (Form 3 / 13)",
            "Completed — Awaiting Controller Decision",
        ],
        'SER' => [
            "Examination Report Received", "Objections Analyzed", "Response Strategy Formulated",
            "Claims Amended / Arguments Drafted", "Internal Review", "Partner Review",
            "Client Communicated", "Response Filed (Form 3 / 13)",
            "Completed — Awaiting Controller Decision",
        ],
        'TER' => [
            "Examination Report Received", "Objections Analyzed", "Response Strategy Formulated",
            "Claims Amended / Arguments Drafted", "Internal Review", "Partner Review",
            "Client Communicated", "Response Filed (Form 3 / 13)",
            "Completed — Awaiting Controller Decision",
        ],
        'HRG' => [
            "Hearing Notice Received", "Hearing Date Set", "Arguments Prepared",
            "Prior Art / Documents Compiled", "Internal Review", "Partner Review",
            "Written Arguments / Counter-Statement Filed", "Hearing Attended", "Awaiting Hearing Order",
        ],
        'GRT' => [
            "Grant Order Received", "Patent Certificate Issued", "Patent Number Recorded",
            "Annual Renewal Date Set (Year 1 from filing)", "Form 27 Filing Schedule Set",
            "Completed — Patent Active",
        ],
        'RNF' => [
            "Renewal Year Identified", "Renewal Fee Due Date Confirmed",
            "Renewal Decision Made by Client", "Renewal Fee Paid", "Completed — Next Renewal Set",
        ],
        'RPO' => [
            "Abandonment Trigger Identified (missed deadline)", "Restoration Petition Evaluated",
            "Decision: Restore or Close", "Completed — Matter Closed",
        ],
        'OPP' => [
            "Opposition Filed / Received", "Opposition Petition Analyzed", "Reply Statement Drafted",
            "Evidence Affidavit Prepared", "Evidence of Opponent Received", "Evidence Reply Prepared",
            "Hearing Scheduled", "Hearing Arguments Prepared", "Hearing Attended",
            "Order Received", "Completed — Patent Maintained or Revoked",
        ],
        '27F' => [
            "Form 27 Due Date Identified", "Working Statement Prepared", "Client Approval", "Form 27 Filed",
        ],
        'ROA' => [
            "Refusal Order Received", "Appeal Decision Made",
            "Completed — Appeal Filed or Matter Closed",
        ],
        'ERH' => [
            "Appeal Decision Made", "Notice of Appeal Filed", "Grounds of Appeal Prepared",
            "Counter-Arguments by Examiner Received", "Reply Filed", "Oral Arguments Scheduled",
            "Hearing Attended", "Judgment / Order Received", "Completed — Decision",
        ],
        '24F' => [
            "Revocation Petition Received", "Reply Statement Prepared", "Evidence Filed",
            "Counter-Evidence Received", "Hearing Scheduled", "Hearing Attended",
            "Order Received", "Completed — Patent Maintained or Revoked",
        ],
    ];

    public function up(): void
    {
        // ── STEP 1: Reseed project_stages for Indian patent projects ────────────
        $projects = DB::table('projects')
            ->where(function ($q) {
                $q->where('project_type', 'like', '%Patent%')
                  ->orWhere('project_type', 'Design')
                  ->orWhere('project_type', 'Trade Secret');
            })
            ->where(function ($q) {
                $q->where('patent_office_code', '!=', 'US')
                  ->orWhereNull('patent_office_code');
            })
            ->whereNull('deleted_at')
            ->select('id', 'service_code', 'status')
            ->get();

        foreach ($projects as $project) {
            $svc = strtoupper(trim($project->service_code ?? ''));
            $newStages = self::STAGES_BY_SERVICE[$svc] ?? null;

            if (empty($newStages)) {
                continue;
            }

            // Collect current stage progress
            $currentStages = DB::table('project_stages')
                ->where('project_id', $project->id)
                ->select('stage_name', 'status')
                ->get();

            $completedOld   = $currentStages->where('status', 'Completed')->pluck('stage_name')->toArray();
            $inProgressOld  = $currentStages->where('status', 'In Progress')->pluck('stage_name')->first();

            // Map old names → new names for progress preservation
            $completedNew = array_map(
                fn($n) => self::STAGE_NAME_MAP[$n] ?? $n,
                $completedOld
            );
            $inProgressNew = $inProgressOld ? (self::STAGE_NAME_MAP[$inProgressOld] ?? $inProgressOld) : null;

            // Delete existing stages
            DB::table('project_stages')->where('project_id', $project->id)->delete();

            // Reseed with new stages
            $foundInProgress = false;
            $rows = [];
            foreach ($newStages as $idx => $stageName) {
                if ($stageName === $inProgressNew && !$foundInProgress) {
                    $status = 'In Progress';
                    $foundInProgress = true;
                } elseif (in_array($stageName, $completedNew, true)) {
                    $status = 'Completed';
                } else {
                    $status = 'Pending';
                }
                $rows[] = [
                    'project_id'     => $project->id,
                    'stage_name'     => $stageName,
                    'status'         => $status,
                    'sequence_order' => $idx,
                    'duration_days'  => 15,
                    'due_date'       => now()->addDays(($idx + 1) * 15),
                    'created_at'     => now(),
                    'updated_at'     => now(),
                ];
            }

            // If no In Progress stage was matched, set the first Pending stage to In Progress
            if (!$foundInProgress) {
                foreach ($rows as &$row) {
                    if ($row['status'] === 'Pending') {
                        $row['status'] = 'In Progress';
                        break;
                    }
                }
                unset($row);
            }

            DB::table('project_stages')->insert($rows);
        }

        // ── STEP 2: Remap tracker_rows.status to new vocabulary ──────────────
        $trackerUpdates = [
            ['ilike', '%grant%',                                              'Granted'],
            ['ilike', '%patent active%',                                      'Granted'],
            ['ilike', '%filed with ipo%',                                     'Filed'],
            ['in',    ['Filed', 'Filing', 'Application Filed'],               'Filed'],
            ['ilike', '%awaiting publication%',                               'Awaiting Publication'],
            ['ilike', '%awaiting first examination%',                         'Awaiting FER'],
            ['ilike', '%awaiting controller decision%',                       'Awaiting Decision'],
            ['ilike', '%response filed%',                                     'FER Response Filed'],
            ['ilike', '%examination report received%',                        'FER Received'],
            ['ilike', '%hearing%',                                            'Hearing Pending'],
            ['ilike', '%renewal%',                                            'Renewal Due'],
            ['ilike', '%abandoned%',                                          'Abandoned'],
            ['ilike', '%refused%',                                            'Refused'],
            ['ilike', '%complet%',                                            'Completed'],
            ['ilike', '%hold%',                                               'On Hold'],
            ['ilike', '%paused%',                                             'On Hold'],
            ['in',    ['Active', 'Ongoing', 'Working', 'Draft Started',
                       'Drafting', 'Internal Review', 'Awaiting IDF'],        'In Progress'],
        ];

        $safeStatuses = ['Granted', 'Filed', 'Awaiting Publication', 'Awaiting FER',
                         'Awaiting Decision', 'FER Response Filed', 'FER Received',
                         'Hearing Pending', 'Renewal Due', 'Abandoned', 'Refused',
                         'Completed', 'On Hold', 'In Progress'];

        foreach ($trackerUpdates as [$op, $needle, $newStatus]) {
            if ($op === 'ilike') {
                DB::table('tracker_rows')
                    ->whereRaw('status ILIKE ?', [$needle])
                    ->whereNotIn('status', $safeStatuses)
                    ->update(['status' => $newStatus, 'updated_at' => now()]);
            } elseif ($op === 'in') {
                DB::table('tracker_rows')
                    ->whereIn('status', $needle)
                    ->update(['status' => $newStatus, 'updated_at' => now()]);
            }
        }

        // Fallback: anything still not in safe list → In Progress
        DB::table('tracker_rows')
            ->whereNotIn('status', $safeStatuses)
            ->update(['status' => 'In Progress', 'updated_at' => now()]);

        // ── STEP 3: Backfill projects.status from updated stages ──────────────

        // Granted: GRT service with Completed — Patent Active stage done
        DB::statement("
            UPDATE projects p
            SET status = 'Granted', updated_at = NOW()
            WHERE p.service_code = 'GRT'
              AND p.status NOT IN ('Closed', 'Abandoned', 'Refused')
              AND EXISTS (
                  SELECT 1 FROM project_stages ps
                  WHERE ps.project_id = p.id
                    AND ps.status = 'Completed'
                    AND ps.stage_name ILIKE '%Patent Active%'
              )
              AND p.deleted_at IS NULL
        ");

        // Abandoned: RPO service completed
        DB::statement("
            UPDATE projects p
            SET status = 'Abandoned', updated_at = NOW()
            WHERE p.service_code = 'RPO'
              AND p.status NOT IN ('Granted', 'Closed', 'Abandoned')
              AND p.deleted_at IS NULL
        ");

        // Refused: ROA service
        DB::statement("
            UPDATE projects p
            SET status = 'Refused', updated_at = NOW()
            WHERE p.service_code = 'ROA'
              AND p.status NOT IN ('Granted', 'Closed', 'Refused', 'Abandoned')
              AND p.deleted_at IS NULL
        ");

        // In Progress: has at least one In Progress stage
        DB::statement("
            UPDATE projects p
            SET status = 'In Progress', updated_at = NOW()
            WHERE p.status NOT IN ('Granted','Refused','Abandoned','Completed','Closed','On Hold')
              AND EXISTS (
                  SELECT 1 FROM project_stages ps
                  WHERE ps.project_id = p.id AND ps.status = 'In Progress'
              )
              AND p.deleted_at IS NULL
        ");

        // Open: no In Progress stage and not in a terminal status
        DB::statement("
            UPDATE projects p
            SET status = 'Open', updated_at = NOW()
            WHERE p.status NOT IN ('Granted','Refused','Abandoned','Completed','Closed','On Hold','In Progress')
              AND NOT EXISTS (
                  SELECT 1 FROM project_stages ps
                  WHERE ps.project_id = p.id AND ps.status = 'In Progress'
              )
              AND p.deleted_at IS NULL
        ");
    }

    public function down(): void
    {
        // The reseed migration is not safely reversible — old stage names are gone.
        // To roll back: restore from a database backup taken before this migration.
    }
};
