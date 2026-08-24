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

        // Unique cases grouped by 9-char case_base (client+seq+office portion of docket_number)
        $totalUniqueCases = (clone $base)
            ->whereNotNull('docket_number')
            ->selectRaw("LEFT(docket_number, 9) as case_base")
            ->groupByRaw("LEFT(docket_number, 9)")
            ->get()->count();

        // Terminal statuses — no active workflow, excluded from pending KPIs
        $terminalStatuses = ['Granted', 'Refused', 'Abandoned', 'Closed', 'Completed'];

        $grantedUniqueCases = (clone $base)
            ->where(fn ($q) => $q->where('status', 'Granted')->orWhere('patent_granted', true))
            ->whereNotNull('docket_number')
            ->selectRaw("LEFT(docket_number, 9) as case_base")
            ->groupByRaw("LEFT(docket_number, 9)")
            ->get()->count();

        // Granted patents by office — driven by status column; patent_granted flag as fallback
        $granted = (clone $base)
            ->where(fn ($q) => $q->where('status', 'Granted')->orWhere('patent_granted', true))
            ->selectRaw('patent_office_code, COUNT(*) as count')
            ->groupBy('patent_office_code')
            ->pluck('count', 'patent_office_code');

        // Pending patents by office — exclude all terminal statuses
        $pending = (clone $base)
            ->whereNotIn('status', $terminalStatuses)
            ->selectRaw('patent_office_code, COUNT(*) as count')
            ->groupBy('patent_office_code')
            ->pluck('count', 'patent_office_code');

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

        // Pending invoices for the client(s)
        $invoicesQ = DB::table('invoices')
            ->whereIn('status', ['Sent', 'Overdue', 'Partially Paid', 'Viewed'])
            ->orderBy('created_at', 'desc')
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
        // ActionItemService, shared with the client dashboard's interactive feed.
        $actionRequired = app(ActionItemService::class)->forBase($base, 100);

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
            'pending_by_office'   => $pending,
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
