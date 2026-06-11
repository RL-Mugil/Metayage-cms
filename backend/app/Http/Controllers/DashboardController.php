<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Task;
use App\Models\TimeEntry;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        return Inertia::render('Dashboard');
    }

    public function metrics(Request $request)
    {
        $user = $request->user();

        // Base metrics query
        $activeMattersQuery = Project::where('status', 'Active');
        $clientsQuery = Client::where('status', 'Active');
        $invoicesQuery = Invoice::query();
        $tasksQuery = Task::where('status', '!=', 'Completed');

        // RBAC client filter
        if ($user->role === 'client') {
            $activeMattersQuery->whereHas('client.contacts', function ($q) use ($user) {
                $q->where('email', $user->email);
            });
            $clientsQuery->whereHas('contacts', function ($q) use ($user) {
                $q->where('email', $user->email);
            });
            $invoicesQuery->whereHas('client.contacts', function ($q) use ($user) {
                $q->where('email', $user->email);
            });
            $tasksQuery->whereHas('project.client.contacts', function ($q) use ($user) {
                $q->where('email', $user->email);
            });
        } elseif (in_array($user->role, ['associate', 'paralegal'])) {
            $activeMattersQuery->where(function ($q) use ($user) {
                $q->where('assigned_manager_id', $user->id)
                  ->orWhere('assigned_partner_id', $user->id)
                  ->orWhereJsonContains('assigned_team', $user->id);
            });
            $tasksQuery->where('assignee_id', $user->id);
        }

        // Calculations
        $activeMattersCount = $activeMattersQuery->count();
        $clientsCount = $clientsQuery->count();
        $tasksCount = $tasksQuery->count();

        // Financial aggregates (WIP & Receipts)
        $wipAmount = TimeEntry::where('status', 'Approved')
            ->where('billable', true)
            ->sum(\DB::raw('duration_hours * 150')); // Assumed standard rate of $150/hr for WIP

        $invoicedAmount = $invoicesQuery->where('status', '!=', 'Draft')->sum('total_amount');
        $receivedAmount = $invoicesQuery->where('status', 'Paid')->sum('total_amount') 
            + $invoicesQuery->where('status', 'Partially Paid')->sum(\DB::raw('total_amount - balance_due'));

        // Stage distribution for charts
        $stagesDist = \DB::table('project_stages')
            ->select('stage_name', \DB::raw('count(*) as count'))
            ->groupBy('stage_name')
            ->orderBy('stage_name')
            ->get();

        return response()->json([
            'metrics' => [
                'active_matters' => $activeMattersCount,
                'clients' => $clientsCount,
                'pending_tasks' => $tasksCount,
                'wip_balance' => $wipAmount,
                'received_payments' => $receivedAmount,
                'invoiced_total' => $invoicedAmount,
                'realization_rate' => $invoicedAmount > 0 ? round(($receivedAmount / $invoicedAmount) * 100, 1) : 100,
            ],
            'charts' => [
                'stage_distribution' => $stagesDist,
            ]
        ]);
    }
}
