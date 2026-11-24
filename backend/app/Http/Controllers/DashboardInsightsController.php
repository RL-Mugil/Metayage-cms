<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\DocketDeadline;
use App\Models\Project;
use App\Models\RegisterPayment;
use App\Models\User;
use App\Services\FinanceFigures;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Dashboard drill-downs and the Financial tab: the lists behind the deadline-risk tiles and
 * the finance KPI cards, and the Financial tab's figures. Same scope rules as the dashboard.
 */
class DashboardInsightsController extends Controller
{
    /** Roles that see money on the dashboard (as DashboardController::metrics). */
    private const FINANCE_ROLES = ['super_admin', 'partner', 'manager', 'finance', 'galvanizer', 'client', 'client_admin', 'client_finance'];

    public function __construct(private readonly FinanceFigures $figures) {}

    /** Open docket deadlines in a risk bucket: overdue, next_7_days, unreviewed, critical. */
    public function deadlines(Request $request): JsonResponse
    {
        $bucket = (string) $request->query('bucket', 'overdue');
        abort_unless(in_array($bucket, ['overdue', 'next_7_days', 'unreviewed', 'critical', 'day'], true), 422, 'Unknown bucket.');

        $query = DocketDeadline::query()->where('status', 'Open')
            ->whereIn('project_id', $this->activeProjects($request->user())->select('id'))
            ->with('project:id,docket_number,project_name,client_id', 'project.client:id,legal_name,company_name,client_code');
        match ($bucket) {
            'overdue' => $query->whereDate('due_date', '<', now()->startOfDay()),
            'next_7_days' => $query->whereBetween('due_date', [now()->startOfDay(), now()->addDays(7)->endOfDay()]),
            'unreviewed' => $query->where('review_status', 'Unreviewed'),
            'critical' => $query->where('risk_level', 'Critical'),
            'day' => $query->whereDate('due_date', Carbon::parse((string) $request->query('date', now()->toDateString()))->toDateString()),
        };
        $today = now()->startOfDay();

        return response()->json($query->orderBy('due_date')->limit(500)->get()->map(fn (DocketDeadline $d) => [
            'id' => $d->id,
            'project_id' => $d->project_id,
            'docket_number' => $d->project?->docket_number,
            'case' => $d->project?->project_name,
            'client' => $d->project?->client?->legal_name ?: $d->project?->client?->company_name,
            'title' => $d->title,
            'due_date' => $d->due_date ? Carbon::parse($d->due_date)->toDateString() : null,
            'days' => $d->due_date ? (int) $today->diffInDays(Carbon::parse($d->due_date)->startOfDay(), false) : null,
            'risk_level' => $d->risk_level,
            'review_status' => $d->review_status,
        ])->values());
    }

    /** Quotes sent or accepted but not invoiced yet (the "Quoted, not invoiced" card). */
    public function quoted(Request $request): JsonResponse
    {
        $this->allowFinance($request);
        $rows = $this->figures->quotedNotInvoiced($request->user())
            ->with('client:id,legal_name,company_name,client_code', 'project:id,docket_number')
            ->orderByDesc('invoice_date')->limit(1000)->get();

        return response()->json($rows->map(fn ($q) => [
            'id' => $q->id, 'number' => $q->invoice_uin, 'status' => $q->zoho_status ?: $q->status,
            'client' => $q->client?->legal_name ?: $q->client?->company_name ?: $q->client_name,
            'client_code' => $q->client?->client_code, 'project_id' => $q->project_id,
            'date' => $q->invoice_date?->toDateString(), 'amount' => (float) ($q->total_bcy ?? $q->invoice_amount),
        ])->values());
    }

    /** Payments received in a month (default this month), by payment date. */
    public function collected(Request $request): JsonResponse
    {
        $this->allowFinance($request);
        $month = $request->filled('month') ? Carbon::parse($request->query('month').'-01') : now();
        $rows = $this->figures->payments($request->user())
            ->whereYear('payment_date', $month->year)->whereMonth('payment_date', $month->month)
            ->with('invoice:id,invoice_uin,client_id,client_name', 'invoice.client:id,legal_name,company_name')
            ->orderByDesc('payment_date')->get();

        return response()->json($rows->map(fn (RegisterPayment $p) => [
            'id' => $p->id, 'date' => $p->payment_date?->toDateString(), 'number' => $p->invoice?->invoice_uin,
            'client' => $p->invoice?->client?->legal_name ?: $p->invoice?->client?->company_name ?: $p->invoice?->client_name,
            'amount' => (float) $p->amount_bcy, 'mode' => $p->payment_mode, 'estimated' => (bool) $p->estimated,
        ])->values());
    }

    /** Dashboard → Financial: totals, ageing, 12 months, recent invoices, top clients owing. */
    public function finance(Request $request): JsonResponse
    {
        $this->allowFinance($request);
        $user = $request->user();
        $summary = $this->figures->summary($user);

        return response()->json([
            'billed' => $summary['billed'],
            'collected' => $summary['received'],
            'outstanding' => $summary['outstanding'],
            'rate' => $summary['billed'] > 0 ? round($summary['received'] / $summary['billed'] * 100, 1) : 0,
            'ageing' => $this->figures->ageing($user),
            'monthly' => $this->figures->monthly($user),
            'recent' => $this->figures->recent($user, 8),
            'top_clients' => $user->isClientRole() ? [] : $this->figures->topClients($user, 5),
            'forecast' => $this->figures->forecast($user),
            'breakdown' => $this->figures->byServiceAndOffice($user),
            'funnel' => $this->figures->funnel($user),
            'unbilled' => $this->figures->unbilled($user),
        ]);
    }

    /** Six-month trend lines for the KPI cards (oldest first). */
    public function trends(Request $request): JsonResponse
    {
        $user = $request->user();
        $months = collect(range(5, 0))->map(fn ($i) => now()->startOfMonth()->subMonths($i));
        $finance = in_array($user->role, self::FINANCE_ROLES, true);
        $monthly = $finance ? collect($this->figures->monthly($user))->keyBy('key') : collect();
        $quotes = $finance ? $this->figures->quotedNotInvoiced($user)->whereNotNull('invoice_date')
            ->where('invoice_date', '>=', $months->first()->toDateString())->get(['invoice_date', 'total_bcy', 'invoice_amount']) : collect();
        $cases = $this->activeProjects($user)->where('created_at', '>=', $months->first())->get(['created_at']);

        return response()->json([
            'months' => $months->map->format('M')->values(),
            'opened' => $months->map(fn ($m) => $cases->filter(fn ($c) => $c->created_at?->format('Y-m') === $m->format('Y-m'))->count())->values(),
            'collected' => $months->map(fn ($m) => (float) ($monthly[$m->format('Y-m')]['collected'] ?? 0))->values(),
            'billed' => $months->map(fn ($m) => (float) ($monthly[$m->format('Y-m')]['billed'] ?? 0))->values(),
            'quoted' => $months->map(fn ($m) => round((float) $quotes->filter(fn ($q) => $q->invoice_date?->format('Y-m') === $m->format('Y-m'))
                ->sum(fn ($q) => (float) ($q->total_bcy ?? $q->invoice_amount)), 2))->values(),
        ]);
    }

    private function allowFinance(Request $request): void
    {
        abort_unless(in_array($request->user()->role, self::FINANCE_ROLES, true), 403);
    }

    /** Active cases the user may see (same rules as the dashboard's scope). */
    private function activeProjects(User $user): Builder
    {
        $query = Project::query()->whereIn('status', ['Open', 'In Progress']);
        if ($user->isClientRole()) {
            $query->whereHas('client', fn ($q) => $q->where('portal_enabled', true)->visibleToUser($user));
        } elseif ($user->isGalvanizer()) {
            $user->applyProjectScope($query);
        } elseif ($user->role === 'associate') {
            $query->assignedTo($user->id);
        } elseif ($user->isInventor()) {
            $query->whereIn('id', $user->projectsAsInventor()->pluck('projects.id'));
        }

        return $query;
    }
}
