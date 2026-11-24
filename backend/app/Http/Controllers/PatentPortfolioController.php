<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Project;
use App\Services\ActionItemService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PatentPortfolioController extends Controller
{
    /** Health, stuck stages and the week's changes for the cases this page shows. */
    public function insights(Request $request, \App\Services\CaseInsights $insights)
    {
        $scope = $this->scopeFor($request);
        if ($scope instanceof \Illuminate\Http\JsonResponse) {
            return $scope;
        }
        [$clientId, $analystIds] = $scope;
        $active = $this->baseQuery($clientId, $analystIds)
            ->whereNotIn('status', ['Granted', 'Refused', 'Abandoned', 'Closed', 'Completed', 'Archived']);
        $projects = (clone $active)->with('client:id,legal_name,company_name')->limit(2000)
            ->get(['id', 'docket_number', 'project_name', 'client_id', 'status', 'service_code', 'patent_office_code', 'hard_deadline', 'priority_date']);
        $health = $insights->healthMany($projects);

        return response()->json([
            'health' => $health,
            'stuck' => $insights->stuck($projects),
            'changes' => $insights->changes($this->baseQuery($clientId, $analystIds)->select('id'), 7, $request->user()->isClientRole()),
        ]);
    }

    /**
     * Who may see what: [client_id or null, project ids or null (= all)], or a 403 response.
     *
     * @return array{0: int|string|null, 1: array<int, int>|null}|\Illuminate\Http\JsonResponse
     */
    private function scopeFor(Request $request)
    {
        $user = $request->user();
        $isClient = $user->isClientRole();

        if ($isClient) {
            // Client portal users are locked to their own client record —
            // any client_id query param is ignored.
            $ownClient = $request->attributes->get('portal_client') ?? Client::forUser($user);
            if (! $ownClient) {
                return response()->json(['message' => 'No client record linked to your account.'], 403);
            }
            $clientId = $ownClient->id;
        } else {
            $clientId = $request->input('client_id');
        }

        // Patent Analysts (associate) only see cases they created or where they
        // are PR, CM, SCM or attorney, or have a task assigned. role_filter narrows to one relationship.
        // Galvanizers see their circle + personally-assigned cases; role_filter
        // lets them narrow to a specific assignment column.
        $analystIds = null;
        if ($user->role === 'associate') {
            $rf = $request->input('role_filter');
            $analystIds = Project::where(function ($q) use ($user, $rf) {
                match ($rf) {
                    'pcm' => $q->where('assigned_manager_id', $user->id),
                    'scm' => $q->where('secondary_manager_id', $user->id),
                    'pr'  => $q->where('patent_engineer_id', $user->id),
                    'attorney' => $q->where('assigned_partner_id', $user->id),
                    default => $q->assignedTo($user->id),
                };
            })->pluck('id')->all();
        } elseif ($user->isGalvanizer()) {
            $rf = $request->input('role_filter');
            $analystIds = Project::where(function ($q) use ($user, $rf) {
                match ($rf) {
                    'pcm' => $q->where('assigned_manager_id', $user->id),
                    'scm' => $q->where('secondary_manager_id', $user->id),
                    'pr'  => $q->where('patent_engineer_id', $user->id),
                    default => $user->applyProjectScope($q),
                };
            })->pluck('id')->all();
        } elseif (in_array($user->role, ['partner', 'director'], true)) {
            $rf = $request->input('role_filter');
            if ($rf && $rf !== 'all') {
                $analystIds = Project::where(function ($q) use ($user, $rf) {
                    match ($rf) {
                        'pcm' => $q->where('assigned_manager_id', $user->id),
                        'scm' => $q->where('secondary_manager_id', $user->id),
                        'pr'  => $q->where('patent_engineer_id', $user->id),
                        default => null,
                    };
                })->pluck('id')->all();
            }
            // else: $analystIds stays null — partner/director sees all
        }


        return [$clientId, $analystIds];
    }

    /** Patent-type cases within the scope. */
    private function baseQuery($clientId, ?array $analystIds)
    {
        return Project::where(function ($q) {
                $q->where('project_type', 'like', '%Patent%')
                  ->orWhere('project_type', 'Design')
                  ->orWhere('project_type', 'Trade Secret');
            })
            ->when($clientId, fn($q) => $q->where('client_id', $clientId))
            ->when($analystIds !== null, fn($q) => $q->whereIn('id', $analystIds));
    }

    public function stats(Request $request)
    {
        $user = $request->user();
        $isClient = $user->isClientRole();
        $scope = $this->scopeFor($request);
        if ($scope instanceof \Illuminate\Http\JsonResponse) {
            return $scope;
        }
        [$clientId, $analystIds] = $scope;

        $base = $this->baseQuery($clientId, $analystIds);

        // Terminal statuses — no active workflow, excluded from pending KPIs. Split into the
        // two flavors the portfolio charts distinguish: an explicit rejection/abandonment vs.
        // a filing that closed out by running its course (e.g. a Provisional/PCT that already
        // converted onward). Granted is terminal too but gets its own bucket/chart.
        $refusedStatuses = ['Refused', 'Abandoned'];
        $closedStatuses = ['Closed', 'Completed', 'Archived'];
        $terminalStatuses = array_merge(['Granted'], $refusedStatuses, $closedStatuses);

        // A "case" is one invention in one office for one client — projects has this
        // pre-normalized as (client_id, invention_number, patent_office_code); docket_number
        // is just those fields concatenated plus a 3-char service_code suffix (FER/HRG/RNF/...),
        // so multiple projects rows (one per service-stage engagement) can share a case.
        // COALESCE guards legacy rows with a NULL invention_number (pre-dates that column) —
        // each becomes its own singleton group instead of colliding with other NULL rows.
        $caseGroups = (clone $base)
            ->selectRaw(
                "client_id,
                 COALESCE(invention_number, CONCAT('_id_', id)) as case_key,
                 patent_office_code,
                 MAX(CASE WHEN status = 'Granted' OR patent_granted = true THEN 1 ELSE 0 END) as is_granted,
                 MAX(CASE WHEN status NOT IN (" . implode(',', array_fill(0, count($terminalStatuses), '?')) . ") THEN 1 ELSE 0 END) as is_active,
                 MAX(CASE WHEN status IN (" . implode(',', array_fill(0, count($refusedStatuses), '?')) . ") THEN 1 ELSE 0 END) as is_refused,
                 MAX(CASE WHEN status IN (" . implode(',', array_fill(0, count($closedStatuses), '?')) . ") THEN 1 ELSE 0 END) as is_closed",
                array_merge($terminalStatuses, $refusedStatuses, $closedStatuses)
            )
            ->groupBy('client_id', 'case_key', 'patent_office_code')
            ->get();

        $totalUniqueCases = $caseGroups->count();
        $grantedUniqueCases = $caseGroups->where('is_granted', 1)->count();

        // Four mutually-exclusive buckets per unique case, by office — every case lands in
        // exactly one, in this priority order:
        //   1. Granted   — any row Granted or patent_granted=true
        //   2. Active    — any row Open/In Progress/On Hold (or any other non-terminal status)
        //   3. Refused   — any row Refused/Abandoned (and none of the above)
        //   4. Closed    — any row Closed/Completed (and none of the above)
        // $terminalStatuses = {Granted} ∪ $refusedStatuses ∪ $closedStatuses, and "Active" is
        // defined as "not terminal" — so these four categories are exhaustive by construction:
        // granted + active + refused + closed always equals total_unique_cases, for any status
        // values that exist now or get added later.
        $granted = collect();
        $active = collect();
        $refused = collect();
        $closed = collect();
        foreach ($caseGroups as $row) {
            if ($row->is_granted) {
                $granted[$row->patent_office_code] = ($granted[$row->patent_office_code] ?? 0) + 1;
            } elseif ($row->is_active) {
                $active[$row->patent_office_code] = ($active[$row->patent_office_code] ?? 0) + 1;
            } elseif ($row->is_refused) {
                $refused[$row->patent_office_code] = ($refused[$row->patent_office_code] ?? 0) + 1;
            } else {
                $closed[$row->patent_office_code] = ($closed[$row->patent_office_code] ?? 0) + 1;
            }
        }

        // Pending by current lifecycle stage (In Progress stage_name on project_stages)
        $pendingByStage = DB::table('project_stages as ps')
            ->join('projects as p', 'ps.project_id', '=', 'p.id')
            ->where(function ($q) {
                $q->where('p.project_type', 'like', '%Patent%')
                  ->orWhere('p.project_type', 'Design')
                  ->orWhere('p.project_type', 'Trade Secret');
            })
            ->whereNotIn('p.status', $terminalStatuses)
            ->where('ps.status', 'In Progress')
            ->when($clientId, fn($q) => $q->where('p.client_id', $clientId))
            ->when($analystIds !== null, fn($q) => $q->whereIn('p.id', $analystIds))
            ->selectRaw('ps.stage_name, COUNT(*) as count')
            ->groupBy('ps.stage_name')
            ->orderByDesc('count')
            ->get();

        // Upcoming renewals: the next unpaid renewal / annuity / US maintenance fee of each
        // granted application in scope (renewal_schedules), overdue first, then soonest.
        $renewals = \App\Services\RenewalOutlook::nextDue((clone $base)->whereNotNull('patent_application_id')->select('patent_application_id'), 1500);   // the renewal calendar needs every next fee

        // Pending invoices for the client(s) — oldest due date first (most overdue/urgent),
        // undated invoices last, so a badly overdue invoice can't be silently pushed out of
        // this 5-row widget by newer ones. Portable NULLS-LAST idiom (works on Postgres, which
        // production runs, and MySQL).
        // Unpaid invoices from the portal register (portal renewals + invoices imported from
        // Zoho Books), most overdue first, undated last. Shown 5 per page on the portfolio.
        $invoicesQ = \App\Models\PatentInvoiceIn::query()->with('project:id,docket_number')
            ->where('type', 'invoice')->whereNotIn('status', ['Draft', 'Cancelled'])
            ->where(fn ($q) => $q->whereNull('payment_status')->orWhere('payment_status', '!=', 'Confirmed'))
            ->where(fn ($q) => $q->whereNull('balance_due')->orWhere('balance_due', '>', 0))
            ->orderByRaw('due_date IS NULL')->orderBy('due_date')
            ->limit(200);
        if ($clientId) {
            $invoicesQ->where('client_id', $clientId);
        }
        // Analysts see payments only for their own cases.
        if ($analystIds !== null) {
            $invoicesQ->whereIn('project_id', $analystIds ?: [-1]);
        }
        $invoices = $invoicesQ->get()->map(fn ($inv) => (object) [
            'id' => $inv->id, 'invoice_code' => $inv->invoice_uin, 'client_id' => $inv->client_id, 'project_id' => $inv->project_id,
            'docket_number' => $inv->project?->docket_number ?? $inv->invoice_uin,
            'total_amount' => (float) $inv->invoice_amount, 'balance_due' => (float) ($inv->balance_due ?? $inv->invoice_amount),
            'currency' => $inv->currency ?: 'INR', 'created_at' => $inv->invoice_date?->toDateString(),
            'due_date' => $inv->due_date?->toDateString(), 'status' => $inv->zoho_status ?: $inv->status,
        ]);

        // Action required: active (non-terminal) projects only. Derivation lives in
        // ActionItemService, shared with the client dashboard's interactive feed. No SQL-level
        // cap — this table is a full paginated/searchable widget on the frontend, not a short
        // dashboard list, and forBase()'s ordering alone isn't a safe thing to LIMIT on top of
        // without risking silently dropping legitimate items (see ActionItemService::forBase()
        // and how every dashboard feed avoids capping before a full sort).
        $actionRequired = app(ActionItemService::class)->forBase($base, null);

        // Client list for the selector.
        // - Clients / associates: hidden (they're locked to their own scope)
        // - Galvanizers: circle-scoped
        // - Partner / director / other staff: full list (even when role_filter narrows projects)
        if ($isClient || $user->role === 'associate') {
            $clients = collect();
        } elseif ($user->isGalvanizer()) {
            $clients = Client::where(fn ($q) => $user->applyClientScope($q))
                ->orderBy('company_name')
                ->get(['id', 'client_code', 'company_name', 'legal_name', 'nationality']);
        } else {
            $clients = Client::orderBy('company_name')->get(['id', 'client_code', 'company_name', 'legal_name', 'nationality']);
        }

        return response()->json([
            'granted_by_office'   => $granted,
            'active_by_office'    => $active,
            'refused_by_office'   => $refused,
            'closed_by_office'    => $closed,
            'pending_by_stage'    => $pendingByStage,
            'upcoming_renewals'   => $renewals,
            'pending_invoices'    => $invoices,
            'action_required'     => $actionRequired,
            'clients'             => $clients,
            'total_unique_cases'  => $totalUniqueCases,
            'granted_unique_cases' => $grantedUniqueCases,
        ]);
    }
}
