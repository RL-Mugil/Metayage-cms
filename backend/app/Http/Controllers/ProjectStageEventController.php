<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Project;
use App\Models\ProjectStage;
use App\Models\ProjectStageEvent;
use App\Services\StageDates;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The case Lifecycle tab's work-stage log: record a stage started / completed on a date
 * (like Record docket event), back-log several stages at once, take suggested dates.
 * Recording needs the same right as moving a stage; reading, the right to view the case.
 */
class ProjectStageEventController extends Controller
{
    public function __construct(private readonly StageDates $dates) {}

    public function index(Request $request, $id): JsonResponse
    {
        $project = Project::findOrFail($id);
        $this->authorize('view', $project);

        return response()->json([
            'events' => $project->stageEvents()->with('creator:id,name')->limit(300)->get()->map(fn (ProjectStageEvent $event) => [
                'id' => $event->id,
                'project_stage_id' => $event->project_stage_id,
                'stage_name' => $event->stage_name,
                'event' => $event->event,
                'event_date' => $event->event_date->toDateString(),
                'source' => $event->source,
                'source_label' => ProjectStageEvent::SOURCE_LABELS[$event->source] ?? $event->source,
                'notes' => $event->notes,
                'created_by' => $event->creator?->name,
                'created_at' => $event->created_at?->toDateTimeString(),
            ]),
            'suggestions' => $request->user()->can('update', $project) ? $this->dates->suggestions($project) : [],
            'can_record' => $request->user()->can('update', $project),
        ]);
    }

    public function store(Request $request, $id): JsonResponse
    {
        $project = Project::findOrFail($id);
        $this->authorize('update', $project);
        $validated = $request->validate([
            'stage_id'   => 'required|integer',
            'event'      => 'required|in:started,completed',
            'event_date' => 'required|date|before_or_equal:'.now()->addDay()->toDateString(),
            'notes'      => 'nullable|string|max:2000',
        ], ['event_date.before_or_equal' => 'A work stage date cannot be in the future.']);
        $stage = ProjectStage::where('project_id', $project->id)->findOrFail($validated['stage_id']);

        $this->dates->record($project, $stage, $validated['event'], Carbon::parse($validated['event_date']), $validated['notes'] ?? null, $request->user()->id);
        $this->audit($request, $project, 'record_stage_event', ['stage' => $stage->stage_name, 'event' => $validated['event'], 'date' => $validated['event_date']]);

        return response()->json(['ok' => true], 201);
    }

    /** Back-log grid: [{stage_id, completed_on?, started_on?}, ...]. */
    public function bulk(Request $request, $id): JsonResponse
    {
        $project = Project::findOrFail($id);
        $this->authorize('update', $project);
        $limit = now()->addDay()->toDateString();
        $validated = $request->validate([
            'rows'                => 'required|array|min:1|max:100',
            'rows.*.stage_id'     => 'required|integer',
            'rows.*.completed_on' => "nullable|date|before_or_equal:{$limit}",
            'rows.*.started_on'   => "nullable|date|before_or_equal:{$limit}",
        ]);
        $ids = $project->stages()->pluck('id')->all();
        $rows = array_values(array_filter($validated['rows'], fn ($row) => in_array((int) $row['stage_id'], $ids, true)));

        $count = $this->dates->backlog($project, $rows, $request->user()->id);
        $this->audit($request, $project, 'backlog_stage_dates', ['dates' => $count]);

        return response()->json(['ok' => true, 'dates' => $count]);
    }

    public function destroy(Request $request, $eventId): JsonResponse
    {
        $event = ProjectStageEvent::findOrFail($eventId);
        $this->authorize('update', $event->project);
        $this->dates->forget($event);
        $this->audit($request, $event->project, 'delete_stage_event', ['stage' => $event->stage_name, 'event' => $event->event, 'date' => $event->event_date->toDateString()]);

        return response()->json(['ok' => true]);
    }

    private function audit(Request $request, Project $project, string $action, array $metadata): void
    {
        AuditLog::create([
            'user_id' => $request->user()->id, 'action' => $action, 'subject_type' => 'Project', 'subject_id' => $project->id,
            'metadata' => $metadata, 'ip_address' => $request->ip(), 'user_agent' => $request->userAgent(),
        ]);
    }
}
