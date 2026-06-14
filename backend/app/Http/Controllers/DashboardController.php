<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Task;
use App\Models\TimeEntry;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
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

        // Calculations — cached per user to avoid N queries on every page load
        $cacheKey = "dashboard_metrics_{$user->id}_{$user->role}";
        [$activeMattersCount, $clientsCount, $tasksCount] = Cache::remember($cacheKey, 300, function () use ($activeMattersQuery, $clientsQuery, $tasksQuery) {
            return [
                $activeMattersQuery->count(),
                $clientsQuery->count(),
                $tasksQuery->count(),
            ];
        });

        // Financial aggregates — only for roles with financial visibility per RBAC matrix.
        // associate, paralegal, hr have Financial = ❌; zeroing out prevents firm-wide data leaks.
        $canSeeFinancials = in_array($user->role, ['super_admin', 'partner', 'manager', 'finance', 'client']);

        $wipAmount      = 0;
        $invoicedAmount = 0;
        $receivedAmount = 0;

        if ($canSeeFinancials) {
            $wipQuery = TimeEntry::where('status', 'Approved')->where('billable', true);
            if ($user->role === 'client') {
                $wipQuery->whereHas('project.client.contacts', fn ($q) => $q->where('email', $user->email));
            }
            $wipAmount = $wipQuery->sum(\DB::raw('duration_hours * 150'));

            $invoiceAgg = (clone $invoicesQuery)->selectRaw("
                COALESCE(SUM(CASE WHEN status != 'Draft' THEN total_amount ELSE 0 END), 0) as invoiced,
                COALESCE(SUM(CASE WHEN status = 'Paid' THEN total_amount ELSE 0 END), 0) as paid,
                COALESCE(SUM(CASE WHEN status = 'Partially Paid' THEN total_amount - balance_due ELSE 0 END), 0) as partial_paid
            ")->first();

            $invoicedAmount = (float) $invoiceAgg->invoiced;
            $receivedAmount = (float) $invoiceAgg->paid + (float) $invoiceAgg->partial_paid;
        }

        // Stage distribution — scoped to projects the user can see
        $stagesQuery = \DB::table('project_stages')
            ->select('stage_name', \DB::raw('count(*) as count'))
            ->groupBy('stage_name')
            ->orderBy('stage_name');

        if ($user->role === 'client') {
            $stagesQuery->whereIn('project_id', (clone $activeMattersQuery)->pluck('id'));
        } elseif (in_array($user->role, ['associate', 'paralegal'])) {
            $stagesQuery->whereIn('project_id', (clone $activeMattersQuery)->pluck('id'));
        }

        $stagesDist = $stagesQuery->get();

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
