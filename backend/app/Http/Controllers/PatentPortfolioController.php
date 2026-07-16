<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Project;
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

        // Enrich invoices with docket number from project
        $invoices = $invoices->map(function ($inv) {
            $project = DB::table('projects')->where('id', $inv->project_id)->first(['docket_number']);
            $inv->docket_number = $project?->docket_number ?? $inv->invoice_code;
            return $inv;
        });

        // Action required: active (non-terminal) projects only
        $actionRequired = (clone $base)
            ->whereNotIn('status', $terminalStatuses)
            ->with(['stages' => fn($q) => $q->where('status', 'In Progress')])
            ->orderByRaw("CASE WHEN urgency='Critical' THEN 1 WHEN urgency='High' THEN 2 ELSE 3 END")
            ->limit(100)
            ->get(['id', 'docket_number', 'filing_date', 'status', 'urgency', 'hard_deadline', 'patent_office_code', 'service_code']);

        $actionRequired = $actionRequired->map(function ($p) {
            $stage = $p->stages->first();
            $svcCode = strtoupper($p->service_code ?? substr($p->docket_number ?? '', -3));

            // Derive human-readable pending action from new stage vocabulary
            $stageName = $stage?->stage_name ?? '';
            $pendingAction = match (true) {
                $p->urgency === 'Critical'                                          => 'Urgent — immediate action needed',
                $p->urgency === 'High'                                              => 'High priority — review and respond',
                // Search / FTO
                str_contains($stageName, 'Disclosure Requested')
                  || str_contains($stageName, 'Awaiting IDF')                       => 'Awaiting IDF',
                str_contains($stageName, 'Prior Art Search In Progress')
                  || str_contains($stageName, 'Search Parameters')                  => 'Prior art search in progress',
                str_contains($stageName, 'Search Report Drafted')                   => 'Search report being drafted',
                str_contains($stageName, 'Search Report Reviewed')                  => 'Search report under internal review',
                str_contains($stageName, 'Search Report Shared')                    => 'Search report shared with client',
                // Drafting
                str_contains($stageName, 'Draft Started')
                  || str_contains($stageName, 'Drafting Started')
                  || str_contains($stageName, 'Specification Drafting Started')     => 'Drafting in progress',
                str_contains($stageName, 'Internal Review')                         => 'Internal review underway',
                str_contains($stageName, 'Corrections Incorporated')                => 'Corrections being incorporated',
                str_contains($stageName, 'Partner Review')                          => 'Partner review underway',
                str_contains($stageName, 'Claims Drafted')
                  || str_contains($stageName, 'Claims Shared')                      => 'Claims — awaiting client approval',
                str_contains($stageName, 'Claims Approved')                         => 'Claims approved — drafting in progress',
                str_contains($stageName, 'Client Review')
                  || str_contains($stageName, 'Shared with Client')
                  || str_contains($stageName, 'Client Feedback')                    => 'Awaiting client approval',
                str_contains($stageName, 'Client Approved')                         => 'Client approved — preparing to file',
                // Filing
                str_contains($stageName, 'Forms Prepared')
                  || str_contains($stageName, 'Government Fees')                    => 'Ready to file',
                str_contains($stageName, 'Filed with IPO')
                  || str_contains($stageName, 'Filed at Receiving Office')          => 'Filed — tracking',
                str_contains($stageName, 'Application Number Received')             => 'Application number received',
                // Post-filing — examination
                str_contains($stageName, 'RFE Filed')
                  || str_contains($stageName, 'Awaiting First Examination')         => 'RFE filed — awaiting FER',
                str_contains($stageName, 'Examination Report Received')             => 'FER received — attorney review needed',
                str_contains($stageName, 'Response Deadline Docketed')              => 'FER received — response deadline running',
                str_contains($stageName, 'Objections Analyzed')
                  || str_contains($stageName, 'Response Strategy')                  => 'FER — strategy being formulated',
                str_contains($stageName, 'Claims Amended')
                  || str_contains($stageName, 'Arguments Drafted')                  => 'FER response being drafted',
                str_contains($stageName, 'Response Filed')                          => 'FER response filed — awaiting decision',
                // Hearing
                str_contains($stageName, 'Hearing Notice')
                  || str_contains($stageName, 'Hearing Date')                       => 'Hearing scheduled',
                str_contains($stageName, 'Arguments Prepared')
                  || str_contains($stageName, 'Prior Art / Documents')              => 'Hearing — preparing arguments',
                str_contains($stageName, 'Written Arguments')
                  || str_contains($stageName, 'Written Submissions')
                  || str_contains($stageName, 'Hearing Attended')                   => 'Hearing attended — awaiting order',
                // Grant / renewal / post-grant
                str_contains($stageName, 'Patent Active')
                  || str_contains($stageName, 'Grant Order')                        => 'Granted',
                str_contains($stageName, 'Renewal')                                 => 'Renewal due',
                str_contains($stageName, 'Opposition')                              => 'Opposition pending',
                str_contains($stageName, 'Appeal')                                  => 'Appeal in progress',
                str_contains($stageName, 'Abandonment')
                  || str_contains($stageName, 'Restoration')
                  || str_contains($stageName, 'Lapse')
                  || str_contains($stageName, 'Restore')                            => 'Abandoned / lapsed — restoration pending',
                str_contains($stageName, 'Withdrawal')                              => 'Withdrawal in progress',
                // Fallback by service code
                in_array($svcCode, ['PAS', 'SRH', 'FTO'])                          => 'Prior art search / patentability assessment',
                in_array($svcCode, ['PRV'])                                         => 'Provisional application — drafting or filing',
                in_array($svcCode, ['CPT', 'CPD', 'CVP', 'CPE'])                   => 'Complete specification — drafting or filing',
                in_array($svcCode, ['PCT'])                                         => 'PCT — national/international filing',
                in_array($svcCode, ['NAP', 'NPE', 'NAF', 'NPA'])                   => 'PCT national phase entry',
                in_array($svcCode, ['FER', 'SER', 'TER'])                          => 'Examination — response to office action required',
                in_array($svcCode, ['HRG'])                                         => 'Hearing — preparation or response required',
                in_array($svcCode, ['GRT'])                                         => 'Granted',
                in_array($svcCode, ['RNF'])                                         => 'Renewal due',
                default                                                             => 'Awaiting next action',
            };

            return [
                'id'                 => $p->id,
                'docket_number'      => $p->docket_number ?? '—',
                'filing_date'        => $p->filing_date,
                'status'             => $p->status,           // actual project status (Open / In Progress / On Hold)
                'current_stage'      => $stage?->stage_name ?? null,
                'service_code'       => $p->service_code ?? null,
                'urgency'            => $p->urgency,
                'patent_office_code' => $p->patent_office_code,
                'pending_action'     => $pendingAction,
            ];
        });

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
