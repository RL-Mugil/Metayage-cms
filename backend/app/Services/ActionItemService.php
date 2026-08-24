<?php

namespace App\Services;

use App\Models\Project;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Derives human-readable "what needs to happen next" action items for a set of
 * projects, from their active lifecycle stage / urgency / service code.
 *
 * This logic originally lived inline in PatentPortfolioController::stats()
 * (the `action_required` block). It's extracted here so the same derivation
 * powers both that existing read-only widget and the new role-specific,
 * interactive client dashboard (DashboardController), instead of drifting
 * into two copies.
 */
class ActionItemService
{
    public const TERMINAL_STATUSES = ['Granted', 'Refused', 'Abandoned', 'Closed', 'Completed'];

    /**
     * Action items for an already-scoped (client/analyst/circle/etc.) Project
     * query builder — mirrors the query PatentPortfolioController::stats() ran
     * inline before this extraction.
     *
     * @return Collection<int, array>
     */
    public function forBase(Builder $base, ?int $limit = 100): Collection
    {
        $query = (clone $base)
            ->whereNotIn('status', self::TERMINAL_STATUSES)
            ->with(['stages' => fn ($q) => $q->where('status', 'In Progress')])
            ->orderByRaw("CASE WHEN urgency='Critical' THEN 1 WHEN urgency='High' THEN 2 ELSE 3 END");

        if ($limit) {
            $query->limit($limit);
        }

        $projects = $query->get([
            'id', 'client_id', 'docket_number', 'project_name', 'filing_date',
            'status', 'urgency', 'hard_deadline', 'patent_office_code', 'service_code',
        ]);

        return $projects->map(fn (Project $p) => $this->mapProject($p));
    }

    /** Same mapping PatentPortfolioController::stats() used inline, unchanged. */
    public function mapProject(Project $p): array
    {
        $stage = $p->relationLoaded('stages') ? $p->stages->first() : null;
        $svcCode = strtoupper($p->service_code ?? substr($p->docket_number ?? '', -3));
        $stageName = $stage?->stage_name ?? '';

        $pendingAction = match (true) {
            $p->urgency === 'Critical' => 'Urgent — immediate action needed',
            $p->urgency === 'High' => 'High priority — review and respond',
            // Search / FTO
            str_contains($stageName, 'Disclosure Requested')
              || str_contains($stageName, 'Awaiting IDF') => 'Awaiting IDF',
            str_contains($stageName, 'Prior Art Search In Progress')
              || str_contains($stageName, 'Search Parameters') => 'Prior art search in progress',
            str_contains($stageName, 'Search Report Drafted') => 'Search report being drafted',
            str_contains($stageName, 'Search Report Reviewed') => 'Search report under internal review',
            str_contains($stageName, 'Search Report Shared') => 'Search report shared with client',
            // Drafting
            str_contains($stageName, 'Draft Started')
              || str_contains($stageName, 'Drafting Started')
              || str_contains($stageName, 'Specification Drafting Started') => 'Drafting in progress',
            str_contains($stageName, 'Internal Review') => 'Internal review underway',
            str_contains($stageName, 'Corrections Incorporated') => 'Corrections being incorporated',
            str_contains($stageName, 'Partner Review') => 'Partner review underway',
            str_contains($stageName, 'Claims Drafted')
              || str_contains($stageName, 'Claims Shared') => 'Claims — awaiting client approval',
            str_contains($stageName, 'Claims Approved') => 'Claims approved — drafting in progress',
            str_contains($stageName, 'Client Review')
              || str_contains($stageName, 'Shared with Client')
              || str_contains($stageName, 'Client Feedback') => 'Awaiting client approval',
            str_contains($stageName, 'Client Approved') => 'Client approved — preparing to file',
            // Filing
            str_contains($stageName, 'Forms Prepared')
              || str_contains($stageName, 'Government Fees') => 'Ready to file',
            str_contains($stageName, 'Filed with IPO')
              || str_contains($stageName, 'Filed at Receiving Office') => 'Filed — tracking',
            str_contains($stageName, 'Application Number Received') => 'Application number received',
            // Post-filing — examination
            str_contains($stageName, 'RFE Filed')
              || str_contains($stageName, 'Awaiting First Examination') => 'RFE filed — awaiting FER',
            str_contains($stageName, 'Examination Report Received') => 'FER received — attorney review needed',
            str_contains($stageName, 'Response Deadline Docketed') => 'FER received — response deadline running',
            str_contains($stageName, 'Objections Analyzed')
              || str_contains($stageName, 'Response Strategy') => 'FER — strategy being formulated',
            str_contains($stageName, 'Claims Amended')
              || str_contains($stageName, 'Arguments Drafted') => 'FER response being drafted',
            str_contains($stageName, 'Response Filed') => 'FER response filed — awaiting decision',
            // Hearing
            str_contains($stageName, 'Hearing Notice')
              || str_contains($stageName, 'Hearing Date') => 'Hearing scheduled',
            str_contains($stageName, 'Arguments Prepared')
              || str_contains($stageName, 'Prior Art / Documents') => 'Hearing — preparing arguments',
            str_contains($stageName, 'Written Arguments')
              || str_contains($stageName, 'Written Submissions')
              || str_contains($stageName, 'Hearing Attended') => 'Hearing attended — awaiting order',
            // Grant / renewal / post-grant
            str_contains($stageName, 'Patent Active')
              || str_contains($stageName, 'Grant Order') => 'Granted',
            str_contains($stageName, 'Renewal') => 'Renewal due',
            str_contains($stageName, 'Opposition') => 'Opposition pending',
            str_contains($stageName, 'Appeal') => 'Appeal in progress',
            str_contains($stageName, 'Abandonment')
              || str_contains($stageName, 'Restoration')
              || str_contains($stageName, 'Lapse')
              || str_contains($stageName, 'Restore') => 'Abandoned / lapsed — restoration pending',
            str_contains($stageName, 'Withdrawal') => 'Withdrawal in progress',
            // Fallback by service code
            in_array($svcCode, ['PAS', 'SRH', 'FTO']) => 'Prior art search / patentability assessment',
            in_array($svcCode, ['PRV']) => 'Provisional application — drafting or filing',
            in_array($svcCode, ['CPT', 'CPD', 'CVP', 'CPE']) => 'Complete specification — drafting or filing',
            in_array($svcCode, ['PCT']) => 'PCT — national/international filing',
            in_array($svcCode, ['NAP', 'NPE', 'NAF', 'NPA']) => 'PCT national phase entry',
            in_array($svcCode, ['FER', 'SER', 'TER']) => 'Examination — response to office action required',
            in_array($svcCode, ['HRG']) => 'Hearing — preparation or response required',
            in_array($svcCode, ['GRT']) => 'Granted',
            in_array($svcCode, ['RNF']) => 'Renewal due',
            default => 'Awaiting next action',
        };

        // Derived independently of $pendingAction — NOT `$pendingAction === 'Renewal due'`.
        // That chain's urgency arms (lines above) short-circuit before any stage check
        // runs, so a Critical/High-urgency renewal would otherwise report is_renewal as
        // false, silently dropping it from financeActionFeed()/the renewal-priority
        // sort and, via ownerFor()'s $isRenewal param, mis-attributing it to 'internal'
        // instead of 'client'. Mirrors the exact conditions that produce 'Renewal due'
        // above (str_contains 'Renewal' / RNF service code).
        $isRenewal = str_contains($stageName, 'Renewal') || in_array($svcCode, ['RNF'], true);

        return [
            'id' => $p->id,
            'client_id' => $p->client_id,
            'docket_number' => $p->docket_number ?? '—',
            'project_name' => $p->project_name,
            'filing_date' => $p->filing_date,
            'status' => $p->status,
            'current_stage' => $stage?->stage_name ?? null,
            'service_code' => $p->service_code ?? null,
            'urgency' => $p->urgency,
            'hard_deadline' => $p->hard_deadline,
            'patent_office_code' => $p->patent_office_code,
            'pending_action' => $pendingAction,
            'is_renewal' => $isRenewal,
            'owner' => $this->ownerFor($stageName, $svcCode, $isRenewal),
            'finance_relevant' => $isRenewal,
        ];
    }

    /**
     * Who the next action is actually owed by: 'client' or 'internal'.
     *
     * Deliberately a *separate* match(true) keyed on the same stage-name /
     * service-code conditions as mapProject()'s $pendingAction chain above —
     * not derived from $pendingAction's text — because that chain's first two
     * arms short-circuit on urgency ("Urgent — immediate action needed" /
     * "High priority...") before any stage check runs. Deriving owner from the
     * final label would lose stage information for every Critical/High item.
     * Keep this chain's conditions mirrored to the one above when either changes.
     */
    private function ownerFor(string $stageName, string $svcCode, bool $isRenewal): string
    {
        if ($isRenewal) {
            return 'client'; // renewal fee approval/payment is always a client action
        }

        return match (true) {
            // Search / FTO — client owes disclosure or is reviewing a shared report
            str_contains($stageName, 'Disclosure Requested')
              || str_contains($stageName, 'Awaiting IDF') => 'client',
            str_contains($stageName, 'Search Report Shared') => 'client',
            // Drafting — client owes approval on claims / shared drafts
            str_contains($stageName, 'Claims Drafted')
              || str_contains($stageName, 'Claims Shared') => 'client',
            str_contains($stageName, 'Client Review')
              || str_contains($stageName, 'Shared with Client')
              || str_contains($stageName, 'Client Feedback') => 'client',
            // Filing — client owes government fee payment
            str_contains($stageName, 'Forms Prepared')
              || str_contains($stageName, 'Government Fees') => 'client',
            // Grant / post-grant — client owes an abandonment/restoration decision
            str_contains($stageName, 'Abandonment')
              || str_contains($stageName, 'Restoration')
              || str_contains($stageName, 'Lapse')
              || str_contains($stageName, 'Restore') => 'client',
            // Renewal by service code (no stage record yet, e.g. fallback path)
            in_array($svcCode, ['RNF'], true) => 'client',
            // Everything else — search/drafting/examination/hearing work the firm
            // owes the client, or no signal at all — default to internal so the
            // client-facing "Action Required" feed doesn't over-flag firm work.
            default => 'internal',
        };
    }

    /**
     * Client-facing action feed: renewals first (abandonment risk if missed —
     * the priority the 397/269 pilot explicitly calls out), then urgency, then
     * nearest deadline. Windowed to items that are either undated (awaiting a
     * response, no calendar deadline yet) or due within ~35 days — pure
     * "someday" deadlines further out don't need to compete for attention on
     * the dashboard's first screen.
     *
     * @return Collection<int, array>
     */
    public function clientActionFeed(int $clientId, int $windowDays = 35): Collection
    {
        return $this->windowAndSort($this->forBase(Project::where('client_id', $clientId), null), $windowDays);
    }

    /**
     * Inventor-facing action feed: same windowing/ordering as clientActionFeed,
     * but scoped by the project_inventors pivot instead of client_id — an
     * inventor can be inventor-of-record across multiple different clients'
     * cases, so there's no single Client to key off (see User::isInventor()).
     *
     * @return Collection<int, array>
     */
    public function inventorActionFeed(int $userId, int $windowDays = 35): Collection
    {
        $base = Project::whereHas('inventors', fn ($q) => $q->where('users.id', $userId));
        return $this->windowAndSort($this->forBase($base, null), $windowDays);
    }

    /**
     * Internal-staff action feed — same windowing/ordering as the portal
     * feeds, but takes an already-scoped Project query builder instead of
     * re-deriving scope, since DashboardController::metrics() already applies
     * manager/associate role_filter and galvanizer circle scoping to its base
     * query before this is called. Full visibility, no owner/finance
     * filtering — just the same derivation surfaced as a feed (with owner/
     * finance_relevant included) instead of only aggregate counts, so the
     * staff dashboard can badge "waiting on client" vs "waiting on us" and
     * open the shared case-detail modal on an "upcoming due" row.
     *
     * @return Collection<int, array>
     */
    public function staffActionFeed(Builder $scopedProjects, int $windowDays = 60, int $limit = 50): Collection
    {
        // No SQL-level limit here — forBase()'s only ordering is a 3-tier urgency CASE
        // with no deterministic tiebreak, so capping at the DB layer before
        // windowAndSort()'s date-window filter runs (in PHP) could silently drop
        // legitimate near-deadline "Normal"-urgency items outside an arbitrary top-N.
        // Fetch everything in scope, filter/sort properly, then cap for display.
        return $this->windowAndSort($this->forBase($scopedProjects, null), $windowDays)->take($limit);
    }

    /**
     * Finance-facing action feed for the client_finance portal role: renewal
     * fees and other finance_relevant items only — never drafting/technical
     * action items, which client_finance has no visibility into
     * (RolePermissions::forRole('client_finance')). Wider default window than
     * clientActionFeed()'s 35 days since renewal-fee/cashflow planning
     * benefits from more lead time.
     *
     * @return Collection<int, array>
     */
    public function financeActionFeed(int $clientId, int $windowDays = 60): Collection
    {
        $items = $this->windowAndSort($this->forBase(Project::where('client_id', $clientId), null), $windowDays);

        return $items->filter(fn (array $item) => $item['finance_relevant'])->values();
    }

    /** @param Collection<int, array> $items */
    private function windowAndSort(Collection $items, int $windowDays): Collection
    {
        $cutoff = now()->addDays($windowDays);
        $items = $items->filter(function (array $item) use ($cutoff) {
            if ($item['is_renewal'] || in_array($item['urgency'], ['Critical', 'High'], true)) {
                return true;
            }
            if (empty($item['hard_deadline'])) {
                // No calendar deadline yet — still an open action item (e.g. awaiting
                // client approval), just not deadline-urgent. Keep it, dashboard sorts it last.
                return true;
            }
            return \Illuminate\Support\Carbon::parse($item['hard_deadline'])->lte($cutoff);
        });

        $urgencyRank = ['Critical' => 0, 'High' => 1];
        return $items->sortBy([
            fn ($item) => $item['is_renewal'] ? 0 : 1,
            fn ($item) => $urgencyRank[$item['urgency']] ?? 2,
            fn ($item) => $item['hard_deadline'] ?? '9999-12-31',
        ])->values();
    }
}
