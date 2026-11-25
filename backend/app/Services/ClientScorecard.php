<?php

namespace App\Services;

use App\Models\Client;
use App\Models\DocketDeadline;
use App\Models\PatentApplication;
use App\Models\Project;
use App\Models\RegisterPayment;
use App\Models\User;
use Carbon\Carbon;

/** One-page monthly summary for a client: cases, deadlines met, renewals ahead, money due. */
class ClientScorecard
{
    public function __construct(private readonly FinanceFigures $figures) {}

    /** @return array<string, mixed> */
    public function build(Client $client, User $viewer, Carbon $month): array
    {
        $start = $month->copy()->startOfMonth();
        $end = $month->copy()->endOfMonth();
        $projects = Project::where('client_id', $client->id);
        $ids = (clone $projects)->select('id');

        $done = DocketDeadline::whereIn('project_id', $ids)->where('status', 'Completed')->whereBetween('completed_at', [$start, $end])->get(['due_date', 'completed_at']);
        $onTime = $done->filter(fn ($d) => $d->completed_at && Carbon::parse($d->completed_at)->startOfDay()->lte(Carbon::parse($d->due_date)))->count();

        $renewals = RenewalOutlook::nextDue(PatentApplication::where('client_id', $client->id)->select('id'), 500)
            ->filter(fn ($r) => $r['days'] <= 90)->values();
        $rows = $this->figures->rows($viewer, $client->id);
        $open = $rows->filter(fn ($r) => $r['outstanding'] > 0);

        return [
            'client' => $client->legal_name ?: $client->company_name,
            'client_code' => $client->client_code,
            'month' => $start->format('F Y'),
            'generated' => now()->format('j M Y'),
            'cases' => [
                'active' => (clone $projects)->whereIn('status', ['Open', 'In Progress'])->count(),
                'opened' => (clone $projects)->whereBetween('created_at', [$start, $end])->count(),
                'granted' => (clone $projects)->where('status', 'Granted')->count(),
            ],
            'deadlines' => [
                'met' => $done->count(), 'on_time' => $onTime,
                'open_overdue' => DocketDeadline::whereIn('project_id', $ids)->where('status', 'Open')->whereDate('due_date', '<', now()->startOfDay())->count(),
                'next' => DocketDeadline::whereIn('project_id', $ids)->where('status', 'Open')->whereDate('due_date', '>=', now()->startOfDay())
                    ->with('project:id,docket_number')->orderBy('due_date')->limit(6)->get()
                    ->map(fn ($d) => ['docket' => $d->project?->docket_number, 'title' => $d->title, 'due' => Carbon::parse($d->due_date)->format('j M Y')])->all(),
            ],
            'renewals' => $renewals->take(8)->map(fn ($r) => ['docket' => $r['docket_number'] ?? $r['application_number'], 'label' => $r['label'],
                'due' => Carbon::parse($r['due_date'])->format('j M Y'), 'overdue' => $r['days'] < 0,
                'estimate' => $r['estimate'] ? $r['estimate']['currency'].' '.number_format($r['estimate']['amount']) : '—'])->all(),
            'renewals_total' => $renewals->count(),
            'money' => [
                'billed_month' => round((float) $rows->filter(fn ($r) => $r['date'] && $r['date']->between($start, $end))->sum('billed')),
                'paid_month' => round((float) RegisterPayment::cash()->where('client_id', $client->id)->whereBetween('payment_date', [$start->toDateString(), $end->toDateString()])->sum('amount_bcy')),
                'outstanding' => round((float) $open->sum('outstanding')),
                'overdue' => round((float) $open->filter(fn ($r) => $r['due_date'] && $r['due_date']->lt(now()->startOfDay()))->sum('outstanding')),
                'open_invoices' => $open->count(),
            ],
        ];
    }
}
