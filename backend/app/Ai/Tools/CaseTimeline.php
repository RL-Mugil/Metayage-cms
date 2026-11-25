<?php

namespace App\Ai\Tools;

use App\Ai\Tool;
use App\Models\AuditLog;
use App\Models\Project;
use App\Models\ProjectStageEvent;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

class CaseTimeline extends Tool
{
    public function name(): string { return 'case_timeline'; }

    public function title(): string { return 'Case timeline'; }

    public function description(): string
    {
        return 'The history of one case: workflow stage events (started, completed, with dates and notes) and recorded changes (status changes, reassignment, stage moves) with who made them, newest first.';
    }

    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => [
            'case_id' => ['type' => 'integer'],
            'docket_number' => ['type' => 'string'],
            'limit' => $this->limitProp(),
        ]];
    }

    public function handle(User $user, array $args): array
    {
        $case = ! empty($args['case_id']) ? Project::findOrFail((int) $args['case_id'])
            : Project::where('docket_number', (string) $this->str($args, 'docket_number'))->firstOrFail();
        Gate::forUser($user)->authorize('view', $case);
        $limit = $this->limit($args, 40);

        $events = ProjectStageEvent::where('project_id', $case->id)->orderByDesc('event_date')->orderByDesc('id')->limit($limit)->get()
            ->map(fn ($e) => ['date' => self::date($e->event_date), 'stage' => $e->stage_name, 'event' => $e->event, 'source' => $e->source, 'notes' => $e->notes]);
        $changes = AuditLog::with('user:id,name')->where('subject_type', 'Project')->where('subject_id', $case->id)
            ->orderByDesc('id')->limit($limit)->get(['id', 'user_id', 'action', 'metadata', 'created_at'])
            ->map(fn ($a) => ['at' => $a->created_at?->timezone('Asia/Kolkata')->format('Y-m-d H:i'), 'by' => $a->user?->name, 'action' => $a->action,
                'details' => is_array($a->metadata) ? array_intersect_key($a->metadata, array_flip(['status', 'from', 'to', 'stage', 'stage_name', 'changes', 'fields', 'old_status', 'new_status', 'reason'])) : null]);

        return ['case' => $case->docket_number.' · '.$case->project_name, 'status' => $case->status,
            'stage_events' => $events->values()->all(), 'changes' => $changes->values()->all()];
    }
}
