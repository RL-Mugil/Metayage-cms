<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\DocketDeadline;
use App\Models\Document;
use App\Models\PatentInvoiceIn;
use App\Models\Project;
use App\Models\ProjectStage;
use App\Models\RegisterPayment;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Case-level signals for the portfolio and case pages: a 0-100 health score with its
 * reasons, stages running past their target, the documents a filing needs, the case's
 * journey, and a plain feed of what changed recently.
 */
class CaseInsights
{
    /**
     * Documents a case normally needs, matched against uploaded file names / folders.
     * keywords are lower-case fragments; any one matching marks the item as present.
     */
    private const CHECKLIST = [
        'all' => [
            ['key' => 'idf', 'label' => 'Invention disclosure (IDF)', 'keywords' => ['idf', 'disclosure']],
            ['key' => 'spec', 'label' => 'Specification draft', 'keywords' => ['specification', 'spec', 'provisional', 'complete', 'draft']],
            ['key' => 'drawings', 'label' => 'Drawings', 'keywords' => ['drawing', 'figure', 'figs']],
        ],
        'IN' => [
            ['key' => 'poa', 'label' => 'Power of attorney (Form 26)', 'keywords' => ['poa', 'power of attorney', 'form 26', 'form-26', 'form26']],
            ['key' => 'form1', 'label' => 'Form 1 — signed by applicant', 'keywords' => ['form 1', 'form-1', 'form1']],
            ['key' => 'form3', 'label' => 'Form 3 (foreign filings)', 'keywords' => ['form 3', 'form-3', 'form3']],
            ['key' => 'form5', 'label' => 'Form 5 (inventorship)', 'keywords' => ['form 5', 'form-5', 'form5'], 'services' => ['CPT', 'NPE', 'CNV', 'PCT']],
        ],
        'US' => [
            ['key' => 'ads', 'label' => 'Application data sheet (ADS)', 'keywords' => ['ads', 'application data sheet']],
            ['key' => 'declaration', 'label' => 'Inventor declaration / oath', 'keywords' => ['declaration', 'oath'], 'services' => ['NPR', 'NPE', 'CNV', 'CPT', 'CON', 'CIP', 'DIV']],
            ['key' => 'poa', 'label' => 'Power of attorney', 'keywords' => ['poa', 'power of attorney']],
        ],
        'priority' => [
            ['key' => 'priority', 'label' => 'Certified priority document', 'keywords' => ['priority', 'certified copy']],
        ],
    ];

    /** Services where a filing checklist makes sense (search/opinion work needs none). */
    private const FILING_SERVICES = ['PRV', 'PRD', 'CPT', 'NPE', 'NPR', 'PCT', 'CNV', 'CON', 'CIP', 'DIV', 'DES'];

    /**
     * Health per project id: {score, level (good|watch|risk), reasons[]}.
     *
     * @param  Collection<int, Project>  $projects
     * @return array<int, array{score: int, level: string, reasons: array<int, string>}>
     */
    public function healthMany(Collection $projects): array
    {
        if ($projects->isEmpty()) {
            return [];
        }
        $ids = $projects->pluck('id');
        $today = now()->startOfDay();
        $nextDeadline = DocketDeadline::whereIn('project_id', $ids)->where('status', 'Open')
            ->selectRaw('project_id, MIN(due_date) as due')->groupBy('project_id')->pluck('due', 'project_id');
        $overduePay = PatentInvoiceIn::where('type', 'invoice')->whereIn('project_id', $ids)->whereNotIn('status', ['Draft', 'Cancelled'])
            ->where('balance_due', '>', 0)->whereDate('due_date', '<', $today)->distinct()->pluck('project_id')->flip();
        $stages = $this->currentStages($ids);
        $docs = $this->documentsFor($ids);

        $out = [];
        foreach ($projects as $p) {
            $score = 100;
            $reasons = [];
            $due = $nextDeadline[$p->id] ?? $p->hard_deadline;
            if ($due) {
                $days = (int) $today->diffInDays(Carbon::parse($due)->startOfDay(), false);
                if ($days < 0) {
                    $score -= 40;
                    $reasons[] = 'Deadline overdue by '.(-$days).' days';
                } elseif ($days <= 7) {
                    $score -= 25;
                    $reasons[] = "Deadline in {$days} days";
                } elseif ($days <= 30) {
                    $score -= 10;
                    $reasons[] = "Deadline in {$days} days";
                }
            }
            if ($stage = $stages->get($p->id)) {
                [$over, $target] = [$stage['days'] - $stage['target'], $stage['target']];
                if ($target > 0 && $over > $target) {
                    $score -= 25;
                    $reasons[] = "{$stage['stage']}: {$stage['days']} days (target {$target})";
                } elseif ($target > 0 && $over > 0) {
                    $score -= 15;
                    $reasons[] = "{$stage['stage']}: {$over} days past target";
                }
            }
            if ($overduePay->has($p->id)) {
                $score -= 20;
                $reasons[] = 'Invoice overdue';
            }
            $missing = collect($this->checklist($p, $docs->get($p->id, collect())))->where('present', false)->count();
            if ($missing > 0) {
                $score -= min(15, $missing * 5);
                $reasons[] = $missing === 1 ? '1 document missing' : "{$missing} documents missing";
            }
            $score = max(0, $score);
            $out[$p->id] = ['score' => $score, 'level' => $score >= 75 ? 'good' : ($score >= 50 ? 'watch' : 'risk'), 'reasons' => $reasons];
        }

        return $out;
    }

    /**
     * Cases whose current stage has run past its target duration, longest overrun first.
     *
     * @param  Collection<int, Project>  $projects
     * @return array<int, array<string, mixed>>
     */
    public function stuck(Collection $projects, int $limit = 50): array
    {
        $byId = $projects->keyBy('id');

        return $this->currentStages($projects->pluck('id'))
            ->filter(fn ($s) => $s['target'] > 0 && $s['days'] > $s['target'])
            ->sortByDesc(fn ($s) => $s['days'] - $s['target'])->take($limit)
            ->map(fn ($s, $id) => [
                'id' => $id, 'docket_number' => $byId[$id]->docket_number ?? null, 'project_name' => $byId[$id]->project_name ?? null,
                'client' => $byId[$id]?->client?->legal_name ?: $byId[$id]?->client?->company_name,
                'stage' => $s['stage'], 'days' => $s['days'], 'target' => $s['target'], 'over' => $s['days'] - $s['target'],
            ])->values()->all();
    }

    /**
     * The documents this case needs and whether each is on file.
     *
     * @param  Collection<int, Document>|null  $docs  pre-loaded documents of the case
     * @return array<int, array{key: string, label: string, present: bool}>
     */
    public function checklist(Project $project, ?Collection $docs = null): array
    {
        $service = strtoupper((string) $project->service_code);
        if ($service !== '' && ! in_array($service, self::FILING_SERVICES, true)) {
            return [];
        }
        $office = strtoupper((string) $project->patent_office_code);
        $items = array_merge(self::CHECKLIST['all'], self::CHECKLIST[$office] ?? []);
        if ($project->priority_date) {
            $items = array_merge($items, self::CHECKLIST['priority']);
        }
        $docs ??= Document::where('project_id', $project->id)->get(['project_id', 'file_name', 'category']);
        $haystack = $docs->map(fn ($d) => strtolower($d->file_name.' '.$d->category))->implode(' | ');

        return collect($items)
            ->filter(fn ($i) => empty($i['services']) || $service === '' || in_array($service, $i['services'], true))
            ->map(fn ($i) => ['key' => $i['key'], 'label' => $i['label'],
                'present' => collect($i['keywords'])->contains(fn ($k) => preg_match('/(^|[^a-z0-9])'.preg_quote($k, '/').'([^a-z0-9]|$)/', $haystack) === 1)])
            ->values()->all();
    }

    /**
     * The case's journey: its stages in order plus office milestones and the next deadlines.
     *
     * @return array{stages: array<int, array<string, mixed>>, milestones: array<int, array<string, mixed>>, upcoming: array<int, array<string, mixed>>}
     */
    public function journey(Project $project): array
    {
        $app = $project->patentApplication;
        $milestones = collect([
            ['label' => 'Priority', 'date' => $project->priority_date],
            ['label' => 'Filed', 'date' => $app?->filing_date ?? $project->filing_date],
            ['label' => 'Published', 'date' => $app?->publication_date],
            ['label' => 'Examination requested', 'date' => $app?->rfe_filed_date],
            ['label' => 'Granted', 'date' => $app?->grant_date],
        ])->filter(fn ($m) => $m['date'])->map(fn ($m) => ['label' => $m['label'], 'date' => Carbon::parse($m['date'])->toDateString()])->values();

        return [
            'stages' => $project->stages()->orderBy('sequence_order')->get(['id', 'stage_name', 'status', 'actual_start_at', 'actual_end_at', 'due_date', 'duration_days'])
                ->map(fn (ProjectStage $s) => [
                    'id' => $s->id, 'name' => $s->stage_name, 'status' => $s->status,
                    'started' => $s->actual_start_at?->toDateString(), 'ended' => $s->actual_end_at?->toDateString(),
                    'due' => $s->due_date?->toDateString(),
                ])->all(),
            'milestones' => $milestones->all(),
            'upcoming' => DocketDeadline::where('project_id', $project->id)->where('status', 'Open')->orderBy('due_date')->limit(4)
                ->get(['id', 'title', 'due_date'])->map(fn ($d) => ['id' => $d->id, 'title' => $d->title, 'date' => Carbon::parse($d->due_date)->toDateString()])->all(),
        ];
    }

    /**
     * What happened recently on these cases, newest first, in plain words.
     *
     * @param  \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder  $projectIds  query selecting project ids
     * @return array<int, array{at: string, kind: string, text: string, project_id: int|null, docket_number: string|null}>
     */
    public function changes($projectIds, int $days = 7, bool $forClient = false, int $limit = 40): array
    {
        $since = now()->subDays($days)->startOfDay();
        $dockets = fn (Collection $ids) => Project::whereIn('id', $ids->filter()->unique())->pluck('docket_number', 'id');
        $items = collect();

        $stageLogs = AuditLog::where('subject_type', 'Project')->where('action', 'stage_change')
            ->whereIn('subject_id', (clone $projectIds))->where('created_at', '>=', $since)->latest()->limit($limit)->get();
        foreach ($stageLogs as $log) {
            $items->push(['at' => $log->created_at, 'kind' => 'stage', 'project_id' => $log->subject_id,
                'text' => 'Moved to '.($log->metadata['new_stage'] ?? 'a new stage')]);
        }
        foreach (DocketDeadline::whereIn('project_id', (clone $projectIds))->where('created_at', '>=', $since)->latest()->limit($limit)->get() as $d) {
            $items->push(['at' => $d->created_at, 'kind' => 'deadline', 'project_id' => $d->project_id,
                'text' => "Deadline added: {$d->title}, due ".Carbon::parse($d->due_date)->format('j M Y')]);
        }
        foreach (PatentInvoiceIn::where('type', 'invoice')->whereNotIn('status', ['Draft', 'Cancelled'])->whereIn('project_id', (clone $projectIds))
            ->where('created_at', '>=', $since)->latest()->limit($limit)->get() as $i) {
            $items->push(['at' => $i->created_at, 'kind' => 'invoice', 'project_id' => $i->project_id,
                'text' => "Invoice {$i->invoice_uin} raised: ₹".number_format((float) ($i->total_bcy ?? $i->invoice_amount))]);
        }
        foreach (RegisterPayment::whereHas('invoice', fn ($q) => $q->whereIn('project_id', (clone $projectIds)))->with('invoice:id,invoice_uin,project_id')
            ->where('payment_date', '>=', $since->toDateString())->orderByDesc('payment_date')->limit($limit)->get() as $pay) {
            $items->push(['at' => Carbon::parse($pay->payment_date), 'kind' => 'payment', 'project_id' => $pay->invoice?->project_id,
                'text' => ($forClient ? 'Payment received, thank you: ₹' : 'Payment received: ₹').number_format((float) $pay->amount_bcy).' for '.$pay->invoice?->invoice_uin]);
        }

        $items = $items->sortByDesc(fn ($i) => $i['at']->timestamp)->take($limit)->values();
        $map = $dockets($items->pluck('project_id'));

        return $items->map(fn ($i) => $i + ['docket_number' => $map[$i['project_id']] ?? null])
            ->map(fn ($i) => array_merge($i, ['at' => $i['at']->toIso8601String()]))->all();
    }

    /**
     * The in-progress stage of each project with days spent and target days.
     *
     * @return Collection<int, array{stage: string, days: int, target: int}>
     */
    private function currentStages(Collection $ids): Collection
    {
        return ProjectStage::whereIn('project_id', $ids)->where('status', 'In Progress')
            ->orderBy('sequence_order')->get(['project_id', 'stage_name', 'actual_start_at', 'created_at', 'duration_days'])
            ->groupBy('project_id')->map(function ($group) {
                $s = $group->last();
                $start = $s->actual_start_at ?? $s->created_at;

                return ['stage' => $s->stage_name, 'days' => $start ? (int) $start->copy()->startOfDay()->diffInDays(now()->startOfDay()) : 0, 'target' => (int) $s->duration_days];
            });
    }

    /** @return Collection<int, Collection<int, Document>> documents grouped by project */
    private function documentsFor(Collection $ids): Collection
    {
        return Document::whereIn('project_id', $ids)->get(['project_id', 'file_name', 'category'])->groupBy('project_id');
    }
}
