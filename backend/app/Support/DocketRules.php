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
        'hearing_notice'    => 'Hearing Notice Received',
        'hearing_held'      => 'Hearing Attended',
        'granted'           => 'Patent Granted',
        'refused'           => 'Application Refused',
        'renewal_missed'    => 'Renewal Fee Missed (Patent Lapsed)',
        'opposition_notice' => 'Pre-Grant Opposition Notice Received',
    ];

    /**
     * @return array<int, array{title: string, legal_basis: string, due_date: Carbon, extended_due_date: ?Carbon}>
     */
    public static function deadlinesFor(string $eventType, Carbon $eventDate, ?PatentApplication $app = null): array
    {
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

        $event = DocketEvent::create([
            'project_id'            => $projectId,
            'patent_application_id' => $applicationId,
            'event_type'            => $eventType,
            'event_date'            => $eventDate,
            'notes'                 => $notes,
            'created_by'            => $userId,
        ]);

        $project = $projectId ? \App\Models\Project::find($projectId) : null;
        app(\App\Services\DeadlineRuleEngine::class)->generate($event, $project, $app);

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
            if ($eventType === 'granted') {
                $updates['grant_date'] = $eventDate;
            }
            if ($updates) {
                $app->update($updates);
            }
            if ($eventType === 'granted') {
                self::generateRenewalSchedule($app, $eventDate);
            }
        }

        return $event;
    }

    /**
     * S.53 / Rule 80 renewal schedule. No fees for years 1–2. Fee for year N
     * is due before the end of year N-1 from the filing date. Fees that fell
     * due before grant accumulate and are payable within 3 months of grant
     * recordal (Rule 80(3)).
     */
    public static function generateRenewalSchedule(PatentApplication $app, Carbon $grantDate): void
    {
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
}
