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

        // RBAC client filter — also enforce portal_enabled so disabling the portal
        // immediately blocks all data access, not just the UI.
        if ($user->isClientRole()) {
            $activeMattersQuery
                ->whereHas('client', fn ($q) => $q->where('portal_enabled', true)->visibleToUser($user));
            $clientsQuery
                ->where('portal_enabled', true)
                ->visibleToUser($user);
            $invoicesQuery
                ->whereHas('client', fn ($q) => $q->where('portal_enabled', true)->visibleToUser($user));
            $tasksQuery
                ->whereHas('project.client', fn ($q) => $q->where('portal_enabled', true)->visibleToUser($user));
        } elseif (in_array($user->role, ['associate', 'paralegal'])) {
            $activeMattersQuery->where(function ($q) use ($user) {
                $q->where('assigned_manager_id', $user->id)
                  ->orWhere('assigned_partner_id', $user->id)
                  ->orWhere('patent_engineer_id', $user->id)
                  ->orWhereJsonContains('assigned_team', $user->id);
            });
            $tasksQuery->where('assignee_id', $user->id);
        }

        // Calculations — cached per user to avoid N queries on every page load.
        // dashboard_v is incremented by any mutative endpoint (projects, tasks, invoices).
        $cacheKey = "dashboard_metrics_{$user->id}_{$user->role}_v" . Cache::get('dashboard_v', 0);
        [$activeMattersCount, $clientsCount, $tasksCount] = Cache::remember($cacheKey, 300, function () use ($activeMattersQuery, $clientsQuery, $tasksQuery) {
            return [
                $activeMattersQuery->count(),
                $clientsQuery->count(),
                $tasksQuery->count(),
            ];
        });

        // Financial aggregates — only for roles with financial visibility per RBAC matrix.
        // associate, paralegal, hr have Financial = ❌; zeroing out prevents firm-wide data leaks.
        $canSeeFinancials = in_array($user->role, ['super_admin', 'partner', 'manager', 'finance', 'client', 'client_admin']);

        $wipAmount      = 0;
        $invoicedAmount = 0;
        $receivedAmount = 0;

        if ($canSeeFinancials) {
            $wipQuery = TimeEntry::where('status', 'Approved')->where('billable', true);
            if ($user->isClientRole()) {
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

        if ($user->isClientRole()) {
            $stagesQuery->whereIn('project_id', (clone $activeMattersQuery)->pluck('id'));
        } elseif (in_array($user->role, ['associate', 'paralegal'])) {
            $stagesQuery->whereIn('project_id', (clone $activeMattersQuery)->pluck('id'));
        }

        $stagesDist = $stagesQuery->get();

        // Month-over-month deltas
        $now        = Carbon::now();
        $thisMonth  = $now->month;
        $thisYear   = $now->year;
        $lastMonth  = $now->copy()->subMonth();

        $mattersThisMonth = (clone $activeMattersQuery)
            ->whereMonth('created_at', $thisMonth)
            ->whereYear('created_at', $thisYear)
            ->count();
        $mattersLastMonth = (clone $activeMattersQuery)
            ->whereMonth('created_at', $lastMonth->month)
            ->whereYear('created_at', $lastMonth->year)
            ->count();

        $clientsThisMonth = (clone $clientsQuery)
            ->whereMonth('created_at', $thisMonth)
            ->whereYear('created_at', $thisYear)
            ->count();
        $clientsLastMonth = (clone $clientsQuery)
            ->whereMonth('created_at', $lastMonth->month)
            ->whereYear('created_at', $lastMonth->year)
            ->count();

        $receivedThisMonth = $canSeeFinancials
            ? (clone $invoicesQuery)->whereMonth('updated_at', $thisMonth)
                ->whereYear('updated_at', $thisYear)
                ->where('status', 'Paid')
                ->sum('total_amount')
            : 0;
        $receivedLastMonth = $canSeeFinancials
            ? (clone $invoicesQuery)->whereMonth('updated_at', $lastMonth->month)
                ->whereYear('updated_at', $lastMonth->year)
                ->where('status', 'Paid')
                ->sum('total_amount')
            : 0;

        $fmtDelta = function (int $curr, int $prev, string $unit = '') {
            $diff = $curr - $prev;
            return $diff >= 0 ? "+{$diff}{$unit} this month" : "{$diff}{$unit} this month";
        };
        $fmtPctDelta = function (float $curr, float $prev) {
            if ($prev == 0) return null;
            $pct = round((($curr - $prev) / $prev) * 100, 1);
            return $pct >= 0 ? "+{$pct}% vs last month" : "{$pct}% vs last month";
        };

        return response()->json([
            'metrics' => [
                'active_matters'        => $activeMattersCount,
                'clients'               => $clientsCount,
                'pending_tasks'         => $tasksCount,
                'wip_balance'           => $wipAmount,
                'received_payments'     => $receivedAmount,
                'invoiced_total'        => $invoicedAmount,
                'realization_rate'      => $invoicedAmount > 0 ? round(($receivedAmount / $invoicedAmount) * 100, 1) : 100,
                'active_matters_delta'  => $fmtDelta($mattersThisMonth, $mattersLastMonth),
                'active_matters_delta_trend' => $mattersThisMonth >= $mattersLastMonth ? 'up' : 'down',
                'clients_delta'         => $fmtDelta($clientsThisMonth, $clientsLastMonth),
                'clients_delta_trend'   => $clientsThisMonth >= $clientsLastMonth ? 'up' : 'down',
                'wip_delta'             => null,
                'wip_delta_trend'       => 'neutral',
                'revenue_delta'         => $fmtPctDelta((float)$receivedThisMonth, (float)$receivedLastMonth),
                'revenue_delta_trend'   => $receivedThisMonth >= $receivedLastMonth ? 'up' : 'down',
            ],
            'charts' => [
                'stage_distribution' => $stagesDist,
            ]
        ]);
    }
}
