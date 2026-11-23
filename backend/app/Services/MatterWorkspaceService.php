<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\ClientLedger;
use App\Models\DiscussionMessage;
use App\Models\DiscussionMessageRead;
use App\Models\DiscussionThread;
use App\Models\DocketDeadline;
use App\Models\DocketEvent;
use App\Models\Document;
use App\Models\Invoice;
use App\Models\Project;
use App\Models\ProjectElevation;
use App\Models\Task;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class MatterWorkspaceService
{
    /** @return array<string, mixed> */
    public function build(Project $project, User $user): array
    {
        $project->load([
            'client:id,company_name,legal_name,client_code,contact_email,portal_user_id,portal_enabled',
            'partner:id,name,email',
            'manager:id,name,email',
            'secondaryManager:id,name,email',
            'patentEngineer:id,name,email',
            'patentApplication.renewals.invoice:id,invoice_uin,patent_office_fees,payment_status,payment_confirmed_at',
            'inventionFamily.client:id,client_code,company_name,legal_name',
            'lifecycleTemplate:id,jurisdiction,service_code,name,version,effective_from',
            'docketReviewer:id,name,email',
            'stages' => fn ($query) => $query->with('owner:id,name')->orderBy('sequence_order'),
        ]);

        $applicationId = $project->patent_application_id;
        $isClient = $user->isClientRole();
        $canViewFinancials = ! $isClient && ! in_array($user->role, ['hr', 'associate'], true);
        $canViewAudit = ! $isClient && in_array($user->role, ['super_admin', 'partner', 'manager', 'galvanizer'], true);

        $tasks = Task::query()
            ->where('project_id', $project->id)
            ->with('assignee:id,name')
            ->orderBy('due_date')
            ->get(['id', 'title', 'status', 'priority', 'due_date', 'assignee_id',
                'estimated_hours', 'actual_hours', 'billable', 'created_at', 'updated_at']);

        $events = DocketEvent::query()
            ->with('creator:id,name')
            ->where(fn ($query) => $this->forMatter($query, $project->id, $applicationId))
            ->orderByDesc('event_date')
            ->get();

        $deadlines = DocketDeadline::query()
            ->with('event:id,event_type,event_date,created_by')
            ->where(fn ($query) => $this->forMatter($query, $project->id, $applicationId))
            ->orderByRaw("CASE WHEN status = 'Open' THEN 0 ELSE 1 END")
            ->orderBy('due_date')
            ->get();

        $documents = Document::query()
            ->with('uploader:id,name')
            ->where('project_id', $project->id)
            ->when($isClient, fn ($query) => $query->where('client_id', $project->client_id))
            ->orderByDesc('updated_at')
            ->get(['id', 'project_id', 'client_id', 'file_name', 'file_type', 'file_size',
                'category', 'storage_path', 'current_version', 'uploaded_by_id', 'status', 'updated_at']);

        $elevations = ProjectElevation::query()
            ->where('project_id', $project->id)
            ->with(['elevatedBy:id,name', 'predecessorProject:id,docket_number,project_code,project_name'])
            ->orderBy('elevated_at')
            ->get();

        $relatedMatters = $this->relatedMatters($project, $user);
        $financials = $canViewFinancials ? $this->financials($project, $elevations) : null;
        $audit = $canViewAudit ? $this->audit($project, $applicationId) : collect();

        return [
            'project' => $project,
            'application' => $project->patentApplication,
            'family' => $project->inventionFamily,
            'family_engagements' => $this->familyEngagements($project, $user),
            'lifecycle_template' => $project->lifecycleTemplate,
            'docket_reviewer' => $project->docketReviewer,
            'allowed_transitions' => ! $isClient && $user->can('update', $project)
                ? app(ServiceTransitionService::class)->available($project)->values()
                : collect(),
            // A stage without its own start begins when the previous stage completed (chain).
            'stages' => app(StageDates::class)->withEffectiveStarts($project->stages)->map(fn (array $row) => [
                'id' => $row['stage']->id,
                'stage_name' => $row['stage']->stage_name,
                'status' => $row['stage']->status,
                'sequence_order' => $row['stage']->sequence_order,
                'due_date' => $row['stage']->due_date,
                'actual_start_at' => $row['stage']->actual_start_at?->toISOString(),
                'actual_end_at' => $row['stage']->actual_end_at?->toISOString(),
                'effective_start_at' => $row['effective_start']?->toISOString(),
                'start_from_previous' => $row['start_from_previous'],
                'start_source' => $row['stage']->start_source,
                'end_source' => $row['stage']->end_source,
                'owner' => $row['stage']->owner,
                'working_days' => $this->workingDays($row['effective_start'], $row['stage']->actual_end_at),
            ])->values(),
            'deadlines' => $deadlines,
            'deadline_summary' => $this->deadlineSummary($deadlines),
            'events' => $events,
            'tasks' => $tasks,
            'documents' => $documents,
            'related_matters' => $relatedMatters,
            'elevations' => $elevations,
            'financials' => $financials,
            'audit' => $audit,
            'chat_unread' => $this->chatUnread($project, $user),
            'timeline' => $this->timeline($project, $events, $documents, $audit),
            'capabilities' => [
                'can_update' => $user->can('update', $project),
                'can_manage_docket' => ! $isClient && $user->can('update', $project),
                'can_view_financials' => $canViewFinancials,
                'can_view_audit' => $canViewAudit,
                'can_prepare_ipo_forms' => $user->can('prepareIpoForms', $project),
            ],
        ];
    }

    /** Unread case-chat messages for this user (excludes their own). */
    private function chatUnread(Project $project, User $user): int
    {
        $thread = DiscussionThread::where('project_id', $project->id)
            ->where('kind', 'case_chat')->first();
        if (! $thread) {
            return 0;
        }

        $lastRead = (int) DiscussionMessageRead::where('thread_id', $thread->id)
            ->where('user_id', $user->id)->value('last_read_message_id');

        return DiscussionMessage::where('thread_id', $thread->id)
            ->where('id', '>', $lastRead)
            ->where('author_id', '!=', $user->id)
            ->count();
    }

    private function familyEngagements(Project $project, User $user): Collection
    {
        if (! $project->invention_family_id) {
            return collect();
        }

        return Project::query()
            ->with('patentApplication:id,application_number,jurisdiction,legal_status')
            ->where('invention_family_id', $project->invention_family_id)
            ->orderBy('patent_office_code')->orderBy('created_at')->get()
            ->filter(fn (Project $engagement) => $user->can('view', $engagement))->values();
    }

    private function forMatter($query, int $projectId, ?int $applicationId): void
    {
        $query->where('project_id', $projectId);
        if ($applicationId !== null) {
            $query->orWhere('patent_application_id', $applicationId);
        }
    }

    /** @return Collection<int, Project> */
    private function relatedMatters(Project $project, User $user): Collection
    {
        $query = Project::query()
            ->with('client:id,company_name,legal_name')
            ->whereKeyNot($project->id)
            ->where(function ($related) use ($project): void {
                if ($project->patent_application_id) {
                    $related->orWhere('patent_application_id', $project->patent_application_id);
                }
                $related->orWhere('parent_project_id', $project->id);
                if ($project->parent_project_id) {
                    $related->orWhereKey($project->parent_project_id);
                }
            })
            ->orderBy('priority_date')
            ->orderBy('filing_date');

        return $query->get()->filter(fn (Project $related) => $user->can('view', $related))->values();
    }

    /** @return array<string, mixed> */
    private function financials(Project $project, Collection $elevations): array
    {
        $projectIds = $elevations->pluck('predecessor_project_id')->filter()->push($project->id)->unique();
        $invoices = Invoice::query()->whereIn('project_id', $projectIds)->orderByDesc('created_at')->get();
        // Register invoices for the case (portal-raised + imported from Zoho Books), shown in the
        // same shape so the Costs tab lists everything billed on it.
        $register = \App\Models\PatentInvoiceIn::query()->whereIn('project_id', $projectIds)->where('type', 'invoice')
            ->orderByRaw('invoice_date DESC NULLS LAST')->get();
        $invoices = $invoices->concat($register->map(fn ($row) => (new Invoice([
            'invoice_code' => $row->invoice_uin, 'status' => $row->status === 'Cancelled' ? 'Cancelled'
                : ($row->balance_due !== null && (float) $row->balance_due <= 0 ? 'Paid' : ($row->status === 'Draft' ? 'Draft' : 'Sent')),
            'total_amount' => (float) $row->invoice_amount, 'balance_due' => $row->balance_due ?? $row->invoice_amount,
            'currency' => $row->currency ?: 'INR', 'issue_date' => $row->invoice_date, 'due_date' => $row->due_date,
        ]))->forceFill(['id' => $row->id, 'created_at' => $row->invoice_date])))->values();
        $ledger = ClientLedger::query()
            ->where(fn ($q) => $q->whereIn('document_reference', $invoices->pluck('invoice_code'))
                ->orWhereIn('source_key', $register->map(fn ($row) => "reg-inv:{$row->id}"))
                ->orWhereIn('source_key', \App\Models\RegisterPayment::whereIn('patent_invoice_in_id', $register->pluck('id'))->pluck('id')->map(fn ($id) => "reg-pay:{$id}")))
            ->orderByDesc('transaction_date')->orderByDesc('id')
            ->get();

        return [
            'invoices' => $invoices,
            'ledger' => $ledger,
            'summary' => [
                'total_invoiced' => $invoices->whereNotIn('status', ['Draft', 'Cancelled'])->sum('total_amount'),
                'total_received' => $invoices->sum(fn ($invoice) => max(0, (float) $invoice->total_amount - (float) $invoice->balance_due)),
                'total_pending' => $invoices->whereIn('status', ['Sent', 'Overdue', 'Partially Paid', 'Viewed'])->sum('balance_due'),
            ],
        ];
    }

    private function audit(Project $project, ?int $applicationId): Collection
    {
        return AuditLog::query()
            ->with('user:id,name')
            ->where(function ($query) use ($project, $applicationId): void {
                $query->where(fn ($projectLog) => $projectLog
                    ->where('subject_type', 'Project')->where('subject_id', $project->id));
                if ($applicationId !== null) {
                    $query->orWhere(fn ($applicationLog) => $applicationLog
                        ->where('subject_type', 'PatentApplication')->where('subject_id', $applicationId));
                }
            })
            ->orderByDesc('created_at')
            ->limit(200)
            ->get();
    }

    private function deadlineSummary(Collection $deadlines): array
    {
        $today = now()->startOfDay();
        $open = $deadlines->where('status', 'Open');

        return [
            'overdue' => $open->filter(fn ($deadline) => $deadline->due_date?->lt($today))->count(),
            'next_7_days' => $open->filter(fn ($deadline) => $deadline->due_date?->betweenIncluded($today, $today->copy()->addDays(7)))->count(),
            'next_30_days' => $open->filter(fn ($deadline) => $deadline->due_date?->betweenIncluded($today, $today->copy()->addDays(30)))->count(),
            'next_90_days' => $open->filter(fn ($deadline) => $deadline->due_date?->betweenIncluded($today, $today->copy()->addDays(90)))->count(),
            'unreviewed' => $open->where('review_status', 'Unreviewed')->count(),
            'nearest_due_date' => $open->filter(fn ($deadline) => $deadline->due_date?->gte($today))->min('due_date')?->toDateString(),
        ];
    }

    private function timeline(Project $project, Collection $events, Collection $documents, Collection $audit): Collection
    {
        $items = collect();
        foreach ($project->stages as $stage) {
            $at = $stage->actual_start_at ?? $stage->actual_end_at;
            if ($at) {
                $items->push(['type' => 'stage', 'title' => $stage->stage_name, 'status' => $stage->status, 'occurred_at' => $at->toISOString()]);
            }
        }
        foreach ($events as $event) {
            $items->push(['type' => 'event', 'title' => $event->event_type, 'status' => null, 'occurred_at' => $event->event_date?->toISOString()]);
        }
        foreach ($documents as $document) {
            $items->push(['type' => 'document', 'title' => $document->file_name, 'status' => $document->status, 'occurred_at' => $document->updated_at?->toISOString()]);
        }
        foreach ($audit as $log) {
            $items->push(['type' => 'audit', 'title' => $log->action, 'status' => null, 'occurred_at' => $log->created_at?->toISOString()]);
        }

        return $items->filter(fn ($item) => $item['occurred_at'] !== null)
            ->sortByDesc('occurred_at')->take(200)->values();
    }

    private function workingDays($start, $end): ?int
    {
        if (! $start || ! $end) {
            return null;
        }

        $from = Carbon::parse($start)->startOfDay();
        $to = Carbon::parse($end)->startOfDay();
        if ($to->lt($from)) {
            return 0;
        }

        return $from->diffInWeekdays($to) + ($to->isWeekday() ? 1 : 0);
    }
}
