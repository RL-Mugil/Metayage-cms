<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Project;
use App\Services\ActionItemService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PatentPortfolioController extends Controller
{
    public function stats(Request $request)
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

        // Patent Analysts (associate) only see cases where they are PR, CM or
        // SCM, or have a task assigned. role_filter narrows to one relationship.
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
                    default => $q->where('patent_engineer_id', $user->id)
                                  ->orWhere('assigned_manager_id', $user->id)
                                  ->orWhere('secondary_manager_id', $user->id)
                                  ->orWhereHas('tasks', fn ($t) => $t->where('assignee_id', $user->id)),
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

        $base = Project::where(function ($q) {
                $q->where('project_type', 'like', '%Patent%')
                  ->orWhere('project_type', 'Design')
                  ->orWhere('project_type', 'Trade Secret');
            })
            ->when($clientId, fn($q) => $q->where('client_id', $clientId))
            ->when($analystIds !== null, fn($q) => $q->whereIn('id', $analystIds));

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

        // Upcoming renewals (projects nearing hard_deadline, sorted soonest first)
        $renewals = (clone $base)
            ->whereNotNull('hard_deadline')
            ->whereNotIn('status', ['Closed'])
            ->with('client')
            ->orderBy('hard_deadline')
            ->limit(5)
            ->get(['id', 'docket_number', 'project_name', 'hard_deadline', 'patent_office_code', 'client_id']);

        // Pending invoices for the client(s) — oldest due date first (most overdue/urgent),
        // undated invoices last, so a badly overdue invoice can't be silently pushed out of
        // this 5-row widget by newer ones. Portable NULLS-LAST idiom (works on Postgres, which
        // production runs, and MySQL).
        $invoicesQ = DB::table('invoices')
            ->whereIn('status', ['Sent', 'Overdue', 'Partially Paid', 'Viewed'])
            ->orderByRaw('due_date IS NULL')
            ->orderBy('due_date')
            ->limit(5);
        if ($clientId) {
            $invoicesQ->where('client_id', $clientId);
        }
        // Analysts see payments only for their own cases.
        if ($analystIds !== null) {
            $invoicesQ->whereIn('project_id', $analystIds ?: [-1]);
        }
        $invoices = $invoicesQ->get(['id', 'invoice_code', 'client_id', 'project_id', 'total_amount', 'balance_due', 'currency', 'created_at', 'status']);

        // Enrich invoices with docket number from project — single batched lookup
        // instead of one query per invoice.
        $projectDockets = DB::table('projects')
            ->whereIn('id', $invoices->pluck('project_id')->filter()->unique())
            ->pluck('docket_number', 'id');
        $invoices = $invoices->map(function ($inv) use ($projectDockets) {
            $inv->docket_number = $projectDockets->get($inv->project_id) ?? $inv->invoice_code;
            return $inv;
        });

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
