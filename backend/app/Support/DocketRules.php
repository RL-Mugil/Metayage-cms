<?php

namespace App\Support;

use App\Models\DocketDeadline;
use App\Models\DocketEvent;
use App\Models\PatentApplication;
use App\Models\RenewalSchedule;
use Carbon\Carbon;

/**
 * Indian patent statutory deadline rules — the docketing engine core.
 * Every rule is anchored to the Patents Act 1970 / Patent Rules 2003
 * as amended through the Patents (Amendment) Rules 2024.
 *
 * An event (FER received, hearing held, granted, …) generates one or
 * more deadline rows. Dates are computed here, never typed by hand.
 */
class DocketRules
{
    public const RULESET_VERSION = 'IN-PAT-2024.1';

    public const EVENT_TYPES = [
        'provisional_filed' => 'Provisional Application Filed',
        'application_filed' => 'Complete Application Filed',
        'pct_filed'         => 'PCT International Application Filed',
        'published'         => 'Application Published (S.11A)',
        'rfe_filed'         => 'Request for Examination Filed',
        'fer_received'      => 'First/Subsequent Examination Report Received',
        'ser_received'      => 'Subsequent Examination Report Received',
        'ter_received'      => 'Further Examination Report Received',
        'us_filing_receipt' => 'USPTO Filing Receipt Received',
        'us_missing_parts' => 'USPTO Missing Parts Notice Received',
        'us_restriction_requirement' => 'USPTO Restriction Requirement Received',
        'us_nonfinal_office_action' => 'USPTO Non-Final Office Action Received',
        'us_final_office_action' => 'USPTO Final Office Action Received',
        'us_advisory_action' => 'USPTO Advisory Action Received',
        'us_notice_of_allowance' => 'USPTO Notice of Allowance Received',
        'us_issue_fee_paid' => 'USPTO Issue Fee Paid',
        'us_patent_issued' => 'USPTO Patent Issued',
        'us_abandoned' => 'USPTO Application Abandoned',
        'us_rce_filed' => 'USPTO RCE Filed',
        'us_ptab_decision' => 'PTAB Decision Received',
        'us_maintenance_window_open' => 'USPTO Maintenance Fee Window Open',
        'us_appeal_notice_filed' => 'USPTO Notice of Appeal Filed',
        'hearing_notice'    => 'Hearing Notice Received',
        'hearing_held'      => 'Hearing Attended',
        'granted'           => 'Patent Granted',
        'refused'           => 'Application Refused',
        'renewal_missed'    => 'Renewal Fee Missed (Patent Lapsed)',
        'opposition_notice' => 'Pre-Grant Opposition Notice Received',
        // Staff-side shortcut for recording a renewal/maintenance fee payment directly —
        // additional to (not a replacement for) the client-portal approve/invoice/pay loop
        // (see RenewalActionController). Handled by its own dedicated endpoint
        // (DocketController::storeRenewalPaid()), not the generic deadline-rule engine —
        // deliberately absent from DEADLINE_TRIGGER_TYPES below.
        'renewal_fee_paid'  => 'Renewal/Maintenance Fees Paid',
    ];

    /** Events that must never be accepted without an active, approved rule. */
    public const DEADLINE_TRIGGER_TYPES = [
        'provisional_filed', 'application_filed', 'pct_filed', 'published',
        'fer_received', 'ser_received', 'ter_received', 'hearing_notice', 'hearing_held',
        'granted', 'refused', 'renewal_missed', 'opposition_notice',
        'us_missing_parts', 'us_restriction_requirement', 'us_nonfinal_office_action',
        'us_final_office_action', 'us_advisory_action', 'us_notice_of_allowance',
        'us_ptab_decision', 'us_maintenance_window_open', 'us_appeal_notice_filed', 'us_abandoned',
        'us_filing_receipt', 'us_patent_issued',
    ];

    /** Events offered on a US case: USPTO events plus the filings, grant and fee payment. */
    private const US_GENERAL_EVENTS = ['provisional_filed', 'application_filed', 'pct_filed', 'granted', 'renewal_fee_paid'];

    /**
     * The event types to offer for a case at this office: a US case gets USPTO events, an
     * Indian (or other) case everything except them. Recording accepts any type.
     *
     * @return array<string, string>
     */
    public static function eventTypesFor(string $jurisdiction): array
    {
        $isUs = strtoupper($jurisdiction) === 'US';

        return array_filter(self::EVENT_TYPES, function (string $type) use ($isUs) {
            $usOnly = str_starts_with($type, 'us_');

            return $isUs ? ($usOnly || in_array($type, self::US_GENERAL_EVENTS, true)) : ! $usOnly;
        }, ARRAY_FILTER_USE_KEY);
    }

    /** Ruleset for US (USPTO) cases: 35 USC, 37 CFR and the MPEP. */
    public const US_RULESET_VERSION = 'US-PAT-2026.1';

    /** The office whose rules apply: the case's patent office, else the application's, else India. */
    public static function jurisdictionOf(?\App\Models\Project $project, ?PatentApplication $app): string
    {
        return strtoupper((string) ($project?->patent_office_code ?: $app?->jurisdiction ?: 'IN'));
    }

    /**
     * @return array<int, array{title: string, legal_basis: string, due_date: Carbon, extended_due_date: ?Carbon}>
     */
    public static function deadlinesFor(string $eventType, Carbon $eventDate, ?PatentApplication $app = null, string $jurisdiction = 'IN'): array
    {
        // A US case follows USPTO rules only; India's rules never apply to it.
        if ($jurisdiction === 'US') {
            return self::usDeadlinesFor($eventType, $eventDate, $app);
        }
        $priority = $app?->priority_date ? Carbon::parse($app->priority_date) : null;

        return match ($eventType) {
            'provisional_filed' => [[
                'title'             => 'Complete Specification Due',
                'legal_basis'       => 'S.9(1) — 12 months from provisional filing',
                'due_date'          => $eventDate->copy()->addMonths(12),
                'extended_due_date' => null,
            ]],
            'application_filed' => array_values(array_filter([
                [
                    'title'             => 'Publication Expected (18 months from earliest priority)',
                    'legal_basis'       => 'S.11A',
                    'due_date'          => ($priority ?? $eventDate)->copy()->addMonths(18),
                    'extended_due_date' => null,
                ],
                [
                    'title'             => 'Request for Examination Due',
                    'legal_basis'       => 'Rule 24B — 31 months from earliest priority (2024 Rules; 48 months if filed before 15.03.2024)',
                    'due_date'          => ($priority ?? $eventDate)->copy()
                        ->addMonths($eventDate->lt(Carbon::create(2024, 3, 15)) ? 48 : 31),
                    'extended_due_date' => null,
                ],
            ])),
            'pct_filed' => [[
                'title'             => 'India National Phase Entry Due',
                'legal_basis'       => 'Rule 20(4)(i) — 31 months from earliest priority',
                'due_date'          => ($priority ?? $eventDate)->copy()->addMonths(31),
                'extended_due_date' => null,
            ]],
            'published' => [[
                'title'             => 'Request for Examination Due (if not yet filed)',
                'legal_basis'       => 'Rule 24B — 31 months from earliest priority (48 months if filed before 15.03.2024)',
                'due_date'          => ($priority ?? $eventDate)->copy()->addMonths(31),
                'extended_due_date' => null,
            ]],
            'fer_received' => [[
                'title'             => 'FER/OA Response Due — application must be put in order for grant',
                'legal_basis'       => 'Rule 24B(5); extension up to 3 months via Form 4 (Rule 24B(6)). Miss → deemed abandoned S.21(1)',
                'due_date'          => $eventDate->copy()->addMonths(6),
                'extended_due_date' => $eventDate->copy()->addMonths(9),
            ]],
            'hearing_notice' => [[
                'title'             => 'Hearing Preparation — adjournment limit applies',
                'legal_basis'       => 'Rule 129A — max 2 adjournments, 30 days each',
                'due_date'          => $eventDate->copy()->addDays(14),
                'extended_due_date' => null,
            ]],
            'hearing_held' => [[
                'title'             => 'Written Submissions Due',
                'legal_basis'       => 'Rule 28(7) — within 15 days of hearing',
                'due_date'          => $eventDate->copy()->addDays(15),
                'extended_due_date' => null,
            ]],
            'granted' => array_values(array_filter([
                [
                    'title'             => 'Accumulated Renewal Fees Due',
                    'legal_basis'       => 'Rule 80(3) — within 3 months of grant recordal (extendable via Form 4)',
                    'due_date'          => $eventDate->copy()->addMonths(3),
                    'extended_due_date' => $eventDate->copy()->addMonths(9),
                ],
                [
                    'title'             => 'First Form 27 Statement of Working Due',
                    'legal_basis'       => '2024 Rules — once every 3 financial years; due within 6 months of the last FY end',
                    'due_date'          => self::firstForm27Due($eventDate),
                    'extended_due_date' => null,
                ],
                [
                    'title'             => 'Post-Grant Opposition Window Closes',
                    'legal_basis'       => 'S.25(2) — 12 months from publication of grant',
                    'due_date'          => $eventDate->copy()->addMonths(12),
                    'extended_due_date' => null,
                ],
            ])),
            'refused' => [
                [
                    'title'             => 'Review Petition Due',
                    'legal_basis'       => 'S.77(1)(f) — within 1 month of the order',
                    'due_date'          => $eventDate->copy()->addMonth(),
                    'extended_due_date' => null,
                ],
                [
                    'title'             => 'Appeal to High Court Due',
                    'legal_basis'       => 'S.117A — within 3 months of the order',
                    'due_date'          => $eventDate->copy()->addMonths(3),
                    'extended_due_date' => null,
                ],
            ],
            'renewal_missed' => [[
                'title'             => 'Restoration Window Closes',
                'legal_basis'       => 'S.60 / Form 15 — within 18 months of lapse',
                'due_date'          => $eventDate->copy()->addMonths(18),
                'extended_due_date' => null,
            ]],
            'opposition_notice' => [[
                'title'             => 'Reply Statement to Pre-Grant Opposition Due',
                'legal_basis'       => 'Rule 55(4) as amended 2024 — within 2 months of notice',
                'due_date'          => $eventDate->copy()->addMonths(2),
                'extended_due_date' => null,
            ]],
            default => [],
        };
    }

    /**
     * USPTO deadlines. Office-action style periods are a shortened statutory period
     * extendable month by month under 37 CFR 1.136(a) up to the 6-month statutory
     * limit (35 USC 133); the extended date is that outer limit. The issue fee and the
     * provisional's 12 months cannot be extended.
     *
     * @return array<int, array{title: string, legal_basis: string, due_date: Carbon, extended_due_date: ?Carbon}>
     */
    public static function usDeadlinesFor(string $eventType, Carbon $eventDate, ?PatentApplication $app = null): array
    {
        $priority = $app?->priority_date ? Carbon::parse($app->priority_date) : null;
        $row = fn (string $title, string $basis, Carbon $due, ?Carbon $outer = null) => [
            'title' => $title, 'legal_basis' => $basis, 'due_date' => $due, 'extended_due_date' => $outer,
        ];

        return match ($eventType) {
            'provisional_filed' => [$row(
                'Non-provisional / PCT application due (claims the provisional)',
                '35 USC 119(e) — 12 months from the provisional filing date; not extendable',
                $eventDate->copy()->addMonths(12),
            )],
            'application_filed', 'us_filing_receipt' => [$row(
                'Publication expected (18 months from earliest priority)',
                '35 USC 122(b) — unless a non-publication request was filed',
                ($priority ?? $eventDate)->copy()->addMonths(18),
            )],
            'pct_filed' => [$row(
                'US national phase entry due',
                '35 USC 371 / 37 CFR 1.495 — 30 months from earliest priority',
                ($priority ?? $eventDate)->copy()->addMonths(30),
            )],
            'us_missing_parts' => [$row(
                'Reply to Notice of Missing Parts due',
                '37 CFR 1.53(f) — 2 months from the notice; extendable under 1.136(a)',
                $eventDate->copy()->addMonths(2),
                $eventDate->copy()->addMonths(7),
            )],
            'us_restriction_requirement' => [$row(
                'Election / response to restriction requirement due',
                '37 CFR 1.142, 1.136(a) — shortened period (usually 2 months), extendable to 6 months',
                $eventDate->copy()->addMonths(2),
                $eventDate->copy()->addMonths(6),
            )],
            'us_nonfinal_office_action' => [$row(
                'Response to non-final office action due',
                '35 USC 133, 37 CFR 1.134 — 3-month shortened period; extendable to 6 months with fees (1.136(a)). Miss → abandoned',
                $eventDate->copy()->addMonths(3),
                $eventDate->copy()->addMonths(6),
            )],
            'us_final_office_action' => [
                $row(
                    'Reply to final office action for advisory-action benefit',
                    'MPEP 706.07(f) — replying within 2 months caps the extension at the advisory action date',
                    $eventDate->copy()->addMonths(2),
                ),
                $row(
                    'Response to final office action due (after-final reply, RCE or notice of appeal)',
                    '35 USC 133, 37 CFR 1.113, 1.114, 41.31 — 3-month shortened period; extendable to 6 months (1.136(a)). Miss → abandoned',
                    $eventDate->copy()->addMonths(3),
                    $eventDate->copy()->addMonths(6),
                ),
            ],
            'us_appeal_notice_filed' => [$row(
                'Appeal brief due',
                '37 CFR 41.37(a) — 2 months from the notice of appeal; extendable under 1.136(a)',
                $eventDate->copy()->addMonths(2),
                $eventDate->copy()->addMonths(7),
            )],
            'us_ptab_decision' => [
                $row(
                    'Request for rehearing at PTAB due',
                    '37 CFR 41.52 — 2 months from the decision',
                    $eventDate->copy()->addMonths(2),
                ),
                $row(
                    'Appeal to the Federal Circuit / civil action due',
                    '35 USC 141, 145; 37 CFR 90.3 — 63 days from the decision',
                    $eventDate->copy()->addDays(63),
                ),
            ],
            'us_notice_of_allowance' => [$row(
                'Issue fee due',
                '35 USC 151, 37 CFR 1.311 — 3 months from the Notice of Allowance; NOT extendable',
                $eventDate->copy()->addMonths(3),
            )],
            'granted', 'us_patent_issued' => [$row(
                'Post-grant review window closes (third parties)',
                '35 USC 321(c) — 9 months from grant; maintenance fees are scheduled separately (3.5 / 7.5 / 11.5 years)',
                $eventDate->copy()->addMonths(9),
            )],
            'us_maintenance_window_open' => [$row(
                'Maintenance fee due (without surcharge)',
                '35 USC 41(b), 37 CFR 1.362 — due 6 months after the window opens; 6-month grace with surcharge',
                $eventDate->copy()->addMonths(6),
                $eventDate->copy()->addMonths(12),
            )],
            'us_abandoned' => [$row(
                'Petition to revive (unintentional delay) — file promptly',
                '37 CFR 1.137(a); MPEP 711.03(c) — file within 2 months of the notice of abandonment',
                $eventDate->copy()->addMonths(2),
            )],
            default => [],
        };
    }

    /**
     * US maintenance fees: 3.5, 7.5 and 11.5 years from grant (35 USC 41(b)). Each
     * window opens 6 months before the due date; a 6-month grace period with a
     * surcharge follows. Stored as renewal_year 4, 8 and 12 (the year it falls in).
     *
     * @return array<int, array{renewal_year: int, label: string, due: Carbon, window_opens: Carbon, grace_ends: Carbon}>
     */
    public static function usMaintenanceSchedule(Carbon $grantDate): array
    {
        $rows = [];
        foreach ([3, 7, 11] as $years) {
            $due = $grantDate->copy()->addYears($years)->addMonths(6);
            $rows[] = [
                'renewal_year' => $years + 1,
                'label'        => self::maintenanceLabel($years + 1),
                'due'          => $due,
                'window_opens' => $due->copy()->subMonths(6),
                'grace_ends'   => $due->copy()->addMonths(6),
            ];
        }

        return $rows;
    }

    /** "Maintenance fee (3.5 years)" for US renewal_year 4 / 8 / 12. */
    public static function maintenanceLabel(int $renewalYear): string
    {
        return 'Maintenance fee ('.($renewalYear - 0.5).' years)';
    }

    /**
     * First Form 27 due date: statement covers 3 financial years starting with
     * the FY commencing after grant; due within 6 months of the last FY end
     * (i.e. by 30 September following that FY).
     */
    private static function firstForm27Due(Carbon $grantDate): Carbon
    {
        $fyStartYear = $grantDate->month >= 4 ? $grantDate->year + 1 : $grantDate->year;
        // 3 FYs: fyStartYear → fyStartYear+3 (ends 31 March), + 6 months = 30 Sept
        return Carbon::create($fyStartYear + 3, 9, 30);
    }

    /**
     * Event types that represent a one-time terminal legal fact — a case can only
     * genuinely be granted, or refused, once. Recording one again (e.g. correcting
     * a date that was auto-set to today because the real date wasn't supplied —
     * see ProjectController::syncApplicationLegalStatus()) must update the existing
     * event in place, never pile up a duplicate event with its own duplicate set
     * of generated deadlines.
     */
    private const SINGLE_INSTANCE_TYPES = ['granted', 'refused'];

    /**
     * Record an event and generate its statutory deadlines. Returns the event.
     */
    public static function recordEvent(
        string $eventType,
        Carbon $eventDate,
        ?int $projectId = null,
        ?int $applicationId = null,
        ?string $notes = null,
        ?int $userId = null
    ): DocketEvent {
        $app = $applicationId ? PatentApplication::find($applicationId) : null;
        $project = $projectId ? \App\Models\Project::find($projectId) : null;

        $existing = ($app && in_array($eventType, self::SINGLE_INSTANCE_TYPES, true))
            ? DocketEvent::where('patent_application_id', $app->id)->where('event_type', $eventType)->first()
            : null;

        if ($existing) {
            $existing->update([
                'project_id'   => $projectId ?? $existing->project_id,
                'ip_record_id' => $projectId ? $project?->ip_record_id : $existing->ip_record_id,
                'event_date'   => $eventDate,
                'notes'        => $notes ?? $existing->notes,
                'created_by'   => $userId ?? $existing->created_by,
            ]);
            $event = $existing;
            // Correcting the date invalidates deadlines computed from the old one —
            // same delete-then-regenerate pattern DocketController::updateEvent() uses.
            $event->deadlines()->delete();
        } else {
            $event = DocketEvent::create([
                'project_id'            => $projectId,
                'ip_record_id'          => $project?->ip_record_id,
                'patent_application_id' => $applicationId,
                'event_type'            => $eventType,
                'event_date'            => $eventDate,
                'notes'                 => $notes,
                'created_by'            => $userId,
            ]);
        }

        self::generateDeadlines($event, $project, $app);

        // Event side-effects on the application's legal status
        if ($app) {
            $newStatus = match ($eventType) {
                'published'      => 'Published',
                'fer_received', 'ser_received', 'ter_received' => 'Under Examination',
                'granted'        => 'Granted',
                'refused'        => 'Refused',
                'renewal_missed' => 'Lapsed',
                default          => null,
            };
            $updates = [];
            if ($newStatus) {
                $updates['legal_status'] = $newStatus;
            }
            if ($eventType === 'published') {
                $updates['publication_date'] = $eventDate;
            }
            if ($eventType === 'rfe_filed') {
                $updates['rfe_filed_date'] = $eventDate;
            }
            if ($eventType === 'us_patent_issued') {
                $updates['legal_status'] = 'Granted';
            }
            if (in_array($eventType, ['granted', 'us_patent_issued'], true)) {
                $updates['grant_date'] = $eventDate;
            }
            if ($updates) {
                $app->update($updates);
            }
            if (in_array($eventType, ['granted', 'us_patent_issued'], true)) {
                self::generateRenewalSchedule($app, $eventDate, self::jurisdictionOf($project, $app));
            }
        }

        // Recording Granted/Refused directly in the docket event log (not via the
        // project status dropdown) must also flip the project's own status badge to
        // match — the two are two doors into the same fact, and must stay in sync
        // both ways. Only touches $project itself, never sibling engagements sharing
        // the same PatentApplication (same scoping rule as StageAdvancementService).
        if ($project && in_array($eventType, self::SINGLE_INSTANCE_TYPES, true)) {
            $mappedStatus = $eventType === 'granted' ? 'Granted' : 'Refused';
            if ($project->status !== $mappedStatus) {
                $project->update(['status' => $mappedStatus]);
            }
        }

        // Event side-effect on the project's own lifecycle stepper — never
        // touches sibling projects (see StageAdvancementService docblock).
        if ($project) {
            app(\App\Services\StageAdvancementService::class)->advance($project, $eventType, $eventDate);
        }

        return $event;
    }

    /** Generate deadlines immediately. Approved database rules take precedence;
     * built-in statutory calculations keep routine docketing usable without a
     * separate approval workflow. */
    public static function generateDeadlines(DocketEvent $event, ?\App\Models\Project $project, ?PatentApplication $app): void
    {
        $generated = app(\App\Services\DeadlineRuleEngine::class)->generate($event, $project, $app);
        if ($generated->isNotEmpty()) {
            return;
        }

        $jurisdiction = self::jurisdictionOf($project, $app);
        $ruleset = $jurisdiction === 'US' ? self::US_RULESET_VERSION : self::RULESET_VERSION;
        foreach (self::deadlinesFor($event->event_type, Carbon::parse($event->event_date), $app, $jurisdiction) as $definition) {
            DocketDeadline::create([
                'docket_event_id' => $event->id,
                'project_id' => $project?->id,
                'ip_record_id' => $project?->ip_record_id,
                'patent_application_id' => $app?->id,
                'title' => $definition['title'],
                'legal_basis' => $definition['legal_basis'],
                'due_date' => $definition['due_date'],
                'statutory_due_date' => $definition['due_date'],
                'extended_due_date' => $definition['extended_due_date'],
                'source_type' => 'Built-in Rule',
                'rule_code' => $ruleset,
                'rule_version' => $ruleset,
                'risk_level' => 'Critical',
                'review_status' => 'Approved',
                'status' => 'Open',
            ]);
        }
    }

    /**
     * S.53 / Rule 80 renewal schedule. No fees for years 1–2. Fee for year N
     * is due before the end of year N-1 from the filing date. Fees that fell
     * due before grant accumulate and are payable within 3 months of grant
     * recordal (Rule 80(3)).
     */
    public static function generateRenewalSchedule(PatentApplication $app, Carbon $grantDate, ?string $jurisdiction = null): void
    {
        $jurisdiction = strtoupper((string) ($jurisdiction ?: $app->projects()->value('patent_office_code') ?: $app->jurisdiction ?: 'IN'));
        if ($jurisdiction === 'US') {
            // US patents pay maintenance fees, not annual renewals.
            foreach (self::usMaintenanceSchedule($grantDate) as $fee) {
                $values = ['due_date' => $fee['due'], 'label' => $fee['label'],
                    'window_opens_on' => $fee['window_opens'], 'grace_ends_on' => $fee['grace_ends']];
                $row = RenewalSchedule::firstOrCreate(
                    ['patent_application_id' => $app->id, 'renewal_year' => $fee['renewal_year']],
                    $values + ['status' => 'Unpaid']
                );
                // Re-running refreshes dates but never touches Paid/Waived rows.
                if ($row->status === 'Unpaid' && ! $row->due_date->isSameDay($fee['due'])) {
                    $row->update($values);
                }
            }

            return;
        }
        if (!$app->filing_date) {
            return;
        }
        $filing = Carbon::parse($app->filing_date);
        $accumulatedDue = $grantDate->copy()->addMonths(3);

        for ($year = 3; $year <= 20; $year++) {
            $normalDue = $filing->copy()->addYears($year - 1);
            $due = $normalDue->lt($grantDate) ? $accumulatedDue : $normalDue;

            $row = RenewalSchedule::firstOrCreate(
                ['patent_application_id' => $app->id, 'renewal_year' => $year],
                ['due_date' => $due, 'status' => 'Unpaid']
            );
            // Re-running the generator refreshes dates but never touches Paid/Waived rows
            if ($row->status === 'Unpaid' && !$row->due_date->isSameDay($due)) {
                $row->update(['due_date' => $due]);
            }
        }
    }

    /**
     * The ProjectStage list for a REN/RNF engagement — "Not renewed" plus one "Renewed
     * till Nth year" entry per renewal year (3-20), replacing the old fixed 5-stage list.
     * Used to seed a REN/RNF project's stages (ProjectController::stagesForServiceCode())
     * and by the renewal-paid docketing action to know which stage name a given year maps
     * to — single source of truth for both, kept in sync with the frontend's
     * STAGES_BY_SERVICE['RNF']/['REN'] copy.
     */
    public static function renewalStageNames(): array
    {
        $stages = ['Not renewed'];
        for ($year = 3; $year <= 20; $year++) {
            $stages[] = self::renewalStageName($year);
        }
        return $stages;
    }

    /** "Renewed till 3rd year" / "4th" / ... / "20th" — ordinal suffix per standard English rules. */
    public static function renewalStageName(int $year): string
    {
        return "Renewed till {$year}" . self::ordinalSuffix($year) . " year";
    }

    private static function ordinalSuffix(int $n): string
    {
        if ($n % 100 >= 11 && $n % 100 <= 13) {
            return 'th';
        }
        return match ($n % 10) {
            1 => 'st',
            2 => 'nd',
            3 => 'rd',
            default => 'th',
        };
    }
}
