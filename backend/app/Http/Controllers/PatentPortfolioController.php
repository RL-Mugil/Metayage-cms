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
        }

        $base = Project::where('project_type', 'like', '%Patent%')
            ->when($clientId, fn($q) => $q->where('client_id', $clientId))
            ->when($analystIds !== null, fn($q) => $q->whereIn('id', $analystIds));

        // Granted patents by office
        $granted = (clone $base)
            ->whereIn('status', ['Granted', 'Completed', 'Closed'])
            ->selectRaw('patent_office_code, COUNT(*) as count')
            ->groupBy('patent_office_code')
            ->pluck('count', 'patent_office_code');

        // Pending patents by office
        $pending = (clone $base)
            ->whereNotIn('status', ['Granted', 'Completed', 'Closed'])
            ->selectRaw('patent_office_code, COUNT(*) as count')
            ->groupBy('patent_office_code')
            ->pluck('count', 'patent_office_code');

        // Pending by tracker status (from tracker_rows.status)
        $pendingByStage = DB::table('tracker_rows as tr')
            ->join('projects as p', 'tr.project_id', '=', 'p.id')
            ->where('p.project_type', 'like', '%Patent%')
            ->whereNotIn('p.status', ['Granted', 'Completed', 'Closed'])
            ->whereNotNull('tr.status')
            ->when($clientId, fn($q) => $q->where('p.client_id', $clientId))
            ->when($analystIds !== null, fn($q) => $q->whereIn('p.id', $analystIds))
            ->selectRaw('tr.status as stage_name, COUNT(*) as count')
            ->groupBy('tr.status')
            ->orderByDesc('count')
            ->get();

        // Upcoming renewals (projects nearing hard_deadline, sorted soonest first)
        $renewals = (clone $base)
            ->whereNotNull('hard_deadline')
            ->whereNotIn('status', ['Granted', 'Completed', 'Closed'])
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

        // Action required: projects with in-progress tracker rows
        $actionRequired = (clone $base)
            ->whereNotIn('status', ['Granted', 'Completed', 'Closed'])
            ->with(['stages' => fn($q) => $q->where('status', 'In Progress')])
            ->orderByRaw("CASE WHEN urgency='Critical' THEN 1 WHEN urgency='High' THEN 2 ELSE 3 END")
            ->limit(100)
            ->get(['id', 'docket_number', 'filing_date', 'status', 'urgency', 'hard_deadline', 'patent_office_code']);

        $actionRequired = $actionRequired->map(function ($p) {
            // Get tracker status
            $trackerStatus = DB::table('tracker_rows')->where('project_id', $p->id)->value('status');
            $stage = $p->stages->first();
            $displayStatus = $trackerStatus ?? $stage?->stage_name ?? $p->status;
            $pendingAction = match (true) {
                $p->urgency === 'Critical'                                  => 'Urgent — immediate action needed',
                $p->urgency === 'High'                                      => 'Review and respond',
                $stage?->stage_name === 'First Examination Report'          => 'FER received — review needed',
                $stage?->stage_name === 'FER Response Preparation'          => 'Need technical inputs',
                $stage?->stage_name === 'FER Response Filing'               => 'Response to be filed',
                $stage?->stage_name === 'Hearing with Examiner'             => 'Hearing to be scheduled',
                $stage?->stage_name === 'Hearing Response Preparation'      => 'Hearing response in preparation',
                $stage?->stage_name === 'Hearing Response Filing'           => 'Hearing response to be filed',
                $stage?->stage_name === 'Filing with Patent Office'         => 'Application to be filed',
                $stage?->stage_name === 'Provisional Filing'                => 'Provisional to be filed',
                $stage?->stage_name === 'Patent Drafting'                   => 'Awaiting draft approval',
                $stage?->stage_name === 'Applicant/Inventor Review'         => 'Awaiting applicant review',
                $stage?->stage_name === 'Patent Search'                     => 'Search in progress',
                $stage?->stage_name === 'Search Report'                     => 'Search report to be shared',
                default                                                     => 'Awaiting next action',
            };
            return [
                'id'                 => $p->id,
                'docket_number'      => $p->docket_number ?? '—',
                'filing_date'        => $p->filing_date,
                'status'             => $displayStatus,
                'tracker_status'     => $trackerStatus,
                'urgency'            => $p->urgency,
                'patent_office_code' => $p->patent_office_code,
                'pending_action'     => $pendingAction,
            ];
        });

        // Client list for the selector — hidden for clients and analysts (both
        // are locked to a scoped view, so there is nothing to switch between).
        $clients = ($isClient || $analystIds !== null)
            ? collect()
            : Client::orderBy('company_name')->get(['id', 'client_code', 'company_name', 'legal_name', 'nationality']);

        return response()->json([
            'granted_by_office'   => $granted,
            'pending_by_office'   => $pending,
            'pending_by_stage'    => $pendingByStage,
            'upcoming_renewals'   => $renewals,
            'pending_invoices'    => $invoices,
            'action_required'     => $actionRequired,
            'clients'             => $clients,
        ]);
    }
}
