<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\ZohoInvoice;
use App\Services\ActionItemService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        return Inertia::render('Dashboard');
    }

    /**
     * client_finance is billing-only — no case/task/deadline visibility at all, so it
     * gets a dedicated, much smaller payload instead of falling through the full
     * (and much more expensive) firm-metrics query below.
     */
    private function financeOnlyMetrics(Request $request)
    {
        $user = $request->user();
        $client = $request->attributes->get('portal_client') ?? Client::forUser($user);

        if (! $client) {
            return response()->json([
                'metrics' => [
                    'zoho_outstanding' => 0, 'zoho_collected_mtd' => 0, 'ledger_balance' => 0,
                    'pending_invoices' => [], 'action_items' => [], 'renewal_items' => [],
                ],
                'charts' => ['stage_distribution' => []],
            ]);
        }

        $zohoQuery = ZohoInvoice::where('zoho_type', 'invoice')->where('client_id', $client->id);
        $zohoOutstanding = (float) (clone $zohoQuery)
            ->whereIn('status', ['overdue', 'unpaid', 'partially_paid', 'sent', 'viewed'])
            ->sum('balance');
        $zohoCollectedMtd = (float) (clone $zohoQuery)
            ->where('status', 'paid')
            ->whereMonth('txn_date', now()->month)
            ->whereYear('txn_date', now()->year)
            ->sum('total');

        $ledgerBalance = (float) (DB::table('client_ledger')
            ->where('client_id', $client->id)
            ->orderByDesc('id')
            ->value('balance') ?? 0);

        $pendingInvoices = Invoice::where('client_id', $client->id)
            ->whereIn('status', ['Sent', 'Overdue', 'Partially Paid', 'Viewed'])
            ->orderByDesc('created_at')
            ->limit(10)
            ->get(['id', 'invoice_code', 'total_amount', 'balance_due', 'currency', 'status', 'due_date']);

        // client_finance needs to see what's coming due and what it'll cost —
        // scoped to finance_relevant (renewal) items only, never drafting/
        // technical action items (see ActionItemService::financeActionFeed()).
        $financeActionItems = app(ActionItemService::class)->financeActionFeed($client->id)->all();
        $renewalItems = array_values(array_filter($financeActionItems, fn ($item) => $item['is_renewal']));

        return response()->json([
            'metrics' => [
                'zoho_outstanding' => $zohoOutstanding,
                'zoho_collected_mtd' => $zohoCollectedMtd,
                'ledger_balance' => $ledgerBalance,
                'pending_invoices' => $pendingInvoices,
                'action_items' => $financeActionItems,
                'renewal_items' => $renewalItems,
            ],
            'charts' => ['stage_distribution' => []],
        ]);
    }

    /**
     * inventor is scoped by the project_inventors pivot, not by any Client — an
     * inventor can be inventor-of-record across multiple different clients'
     * cases. Dedicated payload for the same reason financeOnlyMetrics() is:
     * the big firm-metrics query below has no scoping branch for this role and
     * would otherwise leak the unscoped firm-wide view.
     */
    private function inventorOnlyMetrics(Request $request)
    {
        $user = $request->user();
        $actionItems = app(ActionItemService::class)->inventorActionFeed($user->id)->all();

        return response()->json([
            'metrics' => ['action_items' => $actionItems],
            'charts' => ['stage_distribution' => []],
        ]);
    }

    public function metrics(Request $request)
    {
        $user = $request->user();

        if ($user->role === 'client_finance') {
            return $this->financeOnlyMetrics($request);
        }

        if ($user->isInventor()) {
            return $this->inventorOnlyMetrics($request);
        }

        // Base metrics query
        $activeMattersQuery  = Project::whereIn('status', ['Open', 'In Progress']);
        $inactiveMattersQuery = Project::whereNotIn('status', ['Open', 'In Progress']);
        $clientsQuery = Client::where('status', 'Active');
        $invoicesQuery = Invoice::query();
        $tasksQuery = Task::where('status', '!=', 'Completed');

        // RBAC client filter — also enforce portal_enabled so disabling the portal
        // immediately blocks all data access, not just the UI.
        if ($user->isClientRole()) {
            $activeMattersQuery
                ->whereHas('client', fn ($q) => $q->where('portal_enabled', true)->visibleToUser($user));
            $inactiveMattersQuery
                ->whereHas('client', fn ($q) => $q->where('portal_enabled', true)->visibleToUser($user));
            $clientsQuery
                ->where('portal_enabled', true)
                ->visibleToUser($user);
            $invoicesQuery
                ->whereHas('client', fn ($q) => $q->where('portal_enabled', true)->visibleToUser($user));
            $tasksQuery
                ->whereHas('project.client', fn ($q) => $q->where('portal_enabled', true)->visibleToUser($user));
        } elseif ($user->role === 'manager') {
            // Patent Attorney: optional role-filter to narrow the full-firm view.
            // Without a filter (or filter='all') they see everything — no scope applied.
            $rf = $request->input('role_filter');
            if ($rf && $rf !== 'all') {
                $scopeFn = function ($q) use ($user, $rf) {
                    match ($rf) {
                        'pcm'      => $q->where('assigned_manager_id', $user->id),
                        'scm'      => $q->where('secondary_manager_id', $user->id),
                        'pr'       => $q->where('patent_engineer_id', $user->id),
                        'attorney' => $q->where('assigned_partner_id', $user->id),
                        default    => null,
                    };
                };
                $activeMattersQuery->where($scopeFn);
                $inactiveMattersQuery->where($scopeFn);
            }
        } elseif ($user->role === 'associate') {
            $rf = $request->input('role_filter');
            $scopeFn = function ($q) use ($user, $rf) {
                match ($rf) {
                    'pcm' => $q->where('assigned_manager_id', $user->id),
                    'scm' => $q->where('secondary_manager_id', $user->id),
                    'pr'  => $q->where('patent_engineer_id', $user->id),
                    default => $q->where('patent_engineer_id', $user->id)
                                  ->orWhere('assigned_manager_id', $user->id)
                                  ->orWhere('secondary_manager_id', $user->id)
                                  ->orWhereHas('tasks', fn ($t) => $t->where('assignee_id', $user->id)),
                };
            };
            $activeMattersQuery->where($scopeFn);
            $inactiveMattersQuery->where($scopeFn);
            $tasksQuery->where('assignee_id', $user->id);
        } elseif ($user->isGalvanizer()) {
            $user->applyProjectScope($activeMattersQuery);
            $user->applyProjectScope($inactiveMattersQuery);
            $user->applyClientScope($clientsQuery);
            $invoicesQuery->whereHas('project', fn ($q) => $user->applyProjectScope($q));
            $tasksQuery->whereHas('project', fn ($q) => $user->applyProjectScope($q));
        }

        // Calculations — cached per user to avoid N queries on every page load.
        // dashboard_v is incremented by any mutative endpoint (projects, tasks, invoices).
        $rf = $request->input('role_filter', 'all');
        $grantedMattersQuery = Project::where('patent_granted', true)
            ->when($user->isClientRole(), fn ($q) => $q->whereHas('client', fn ($cq) => $cq->where('portal_enabled', true)->visibleToUser($user)))
            ->when($user->isGalvanizer(), fn ($q) => $user->applyProjectScope($q));

        $cacheKey = "dashboard_metrics_{$user->id}_{$user->role}_{$rf}_v" . Cache::get('dashboard_v', 0);
        [$activeMattersCount, $inactiveMattersCount, $clientsCount, $tasksCount, $grantedMattersCount] = Cache::remember($cacheKey, 300, function () use ($activeMattersQuery, $inactiveMattersQuery, $clientsQuery, $tasksQuery, $grantedMattersQuery) {
            return [
                $activeMattersQuery->count(),
                $inactiveMattersQuery->count(),
                $clientsQuery->count(),
                $tasksQuery->count(),
                $grantedMattersQuery->count(),
            ];
        });

        // Distinct matters = active projects that are NOT a successor in any elevation chain.
        // i.e. projects where their ID does not appear as predecessor_project_id in project_elevations.
        // These are "head of chain" projects — distinct IP matters, not service continuations.
        $successorProjectIds = \DB::table('project_elevations')
            ->whereNotNull('predecessor_project_id')
            ->pluck('project_id')
            ->unique();
        $distinctMattersCount = (clone $activeMattersQuery)
            ->whereNotIn('id', $successorProjectIds)
            ->count();

        $visibleProjectIds = (clone $activeMattersQuery)->pluck('id');
        $deadlineBase = \App\Models\DocketDeadline::query()
            ->whereIn('project_id', $visibleProjectIds)->where('status', 'Open');
        $deadlineRisk = [
            'overdue' => (clone $deadlineBase)->whereDate('due_date', '<', now()->startOfDay())->count(),
            'next_7_days' => (clone $deadlineBase)->whereBetween('due_date', [now()->startOfDay(), now()->addDays(7)->endOfDay()])->count(),
            'unreviewed' => (clone $deadlineBase)->where('review_status', 'Unreviewed')->count(),
            'critical' => (clone $deadlineBase)->where('risk_level', 'Critical')->count(),
        ];

        // Financial aggregates — only for roles with financial visibility per RBAC matrix.
        // associate, paralegal, hr have Financial = ❌; zeroing out prevents firm-wide data leaks.
        $canSeeFinancials = in_array($user->role, ['super_admin', 'partner', 'manager', 'finance', 'galvanizer', 'client', 'client_admin']);

        $wipAmount      = 0;
        $invoicedAmount = 0;
        $receivedAmount = 0;
        $zohoOutstanding  = 0;
        $zohoCollectedMtd = 0;

        if ($canSeeFinancials) {
            // Zoho Books figures — sourced from the local zoho_invoices mirror (kept fresh by
            // the zoho:sync command), not a live call. Supplements, doesn't replace, the
            // Invoice-based figures below (Zoho is a separate, unreconciled system of record).
            $zohoQuery = \App\Models\ZohoInvoice::where('zoho_type', 'invoice');
            if ($user->isClientRole()) {
                $zohoQuery->where('client_id', optional(Client::forUser($user))->id ?? 0);
            } elseif ($user->isGalvanizer()) {
                $zohoQuery->whereHas('project', fn ($q) => $user->applyProjectScope($q));
            }
            $zohoOutstanding = (float) (clone $zohoQuery)
                ->whereIn('status', ['overdue', 'unpaid', 'partially_paid', 'sent', 'viewed'])
                ->sum('balance');
            $zohoCollectedMtd = (float) (clone $zohoQuery)
                ->where('status', 'paid')
                ->whereMonth('txn_date', now()->month)
                ->whereYear('txn_date', now()->year)
                ->sum('total');

            $wipQuery = TimeEntry::where('status', 'Approved')->where('billable', true);
            if ($user->isClientRole()) {
                $wipQuery->whereHas('project.client.contacts', fn ($q) => $q->where('email', $user->email));
            } elseif ($user->isGalvanizer()) {
                $wipQuery->whereHas('project', fn ($q) => $user->applyProjectScope($q));
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

        // Interactive action feed — client/client_admin only. Renewals-first, urgency,
        // then nearest deadline (see ActionItemService::clientActionFeed). Not part of
        // the coarse cache above since it's cheap and role-specific to begin with.
        $actionItems = [];
        if (in_array($user->role, ['client', 'client_admin'], true)) {
            $portalClient = $request->attributes->get('portal_client') ?? Client::forUser($user);
            if ($portalClient) {
                $actionItems = app(ActionItemService::class)->clientActionFeed($portalClient->id)->all();
            }
        } else {
            // Internal staff — same "more intelligence, not narrowing" scope
            // already applied to $activeMattersQuery above (manager/associate
            // role_filter, galvanizer circle). Feeds owner/finance_relevant
            // badges + the shared case-detail modal on the staff dashboard,
            // full visibility preserved (see Initiative 2b).
            $actionItems = app(ActionItemService::class)->staffActionFeed(clone $activeMattersQuery)->all();
        }

        // Stage distribution — only count stages currently "In Progress"
        $stagesQuery = \DB::table('project_stages')
            ->select('stage_name', \DB::raw('count(*) as count'))
            ->where('status', 'In Progress')
            ->groupBy('stage_name')
            ->orderBy('stage_name');

        if ($user->isClientRole() || $user->role === 'associate' || $user->isGalvanizer() ||
            ($user->role === 'manager' && $request->input('role_filter') && $request->input('role_filter') !== 'all')) {
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
                'distinct_matters'      => $distinctMattersCount,
                'inactive_matters'      => $inactiveMattersCount,
                'granted_matters'       => $grantedMattersCount,
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
                'deadline_risk'         => $deadlineRisk,
                'zoho_outstanding'      => $zohoOutstanding,
                'zoho_collected_mtd'    => $zohoCollectedMtd,
                'action_items'          => $actionItems,
            ],
            'charts' => [
                'stage_distribution' => $stagesDist,
            ]
        ]);
    }
}
