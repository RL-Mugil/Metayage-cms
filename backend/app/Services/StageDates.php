<?php

namespace App\Services;

use App\Models\DocketEvent;
use App\Models\Project;
use App\Models\ProjectStage;
use App\Models\ProjectStageEvent;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Start / end dates of a case's work stages (the Lifecycle tab, same stages as the
 * Project Tracker), and the log behind them.
 *
 * - Recorded dates (manual / back-log) always win and are never overwritten automatically.
 * - Moving a stage (tracker, stage picker, docket event, case status) logs today only on the
 *   stage being left and the stage being started, and only where no date is recorded.
 *   Stages jumped over become Completed without a date: unknown, to be back-logged.
 * - A stage without its own start date starts when the previous stage completed (chain).
 */
class StageDates
{
    public const MANUAL = 'manual';
    public const AUTO = 'auto';

    private function log(Project $project, ProjectStage $stage, string $event, Carbon $date, string $source, ?string $notes, ?int $userId): void
    {
        ProjectStageEvent::create([
            'project_id' => $project->id, 'project_stage_id' => $stage->id, 'stage_name' => $stage->stage_name,
            'event' => $event, 'event_date' => $date->toDateString(), 'source' => $source, 'notes' => $notes,
            'created_by' => $userId ?? auth()->id(),
        ]);
    }

    /** Fills a stage's start or end automatically, only when nothing is recorded there. */
    private function autoFill(Project $project, ProjectStage $stage, string $event, Carbon $date, string $source, ?int $userId): void
    {
        $column = $event === 'started' ? 'actual_start_at' : 'actual_end_at';
        if ($stage->{$column} !== null) {
            return;
        }
        $stage->update([$column => $date, ($event === 'started' ? 'start_source' : 'end_source') => self::AUTO]);
        $this->log($project, $stage, $event, $date, $source, null, $userId);
    }

    /** Clears dates on stages put back to Pending, keeping recorded (manual) ones. */
    private function resetLater(Project $project, int $afterOrder): void
    {
        foreach ($project->stages()->where('sequence_order', '>', $afterOrder)->get() as $stage) {
            $changes = ['status' => 'Pending'];
            if ($stage->start_source !== self::MANUAL) {
                $changes += ['actual_start_at' => null, 'start_source' => null];
            }
            if ($stage->end_source !== self::MANUAL) {
                $changes += ['actual_end_at' => null, 'end_source' => null];
            }
            $stage->update($changes);
        }
    }

    /**
     * A stage move while working (Project Tracker, Set workflow stage, a filing date):
     * earlier stages Completed, the target In Progress, later ones Pending.
     */
    public function moveTo(Project $project, ProjectStage $target, string $source, ?Carbon $date = null, ?int $userId = null): void
    {
        $date ??= Carbon::now();
        DB::transaction(function () use ($project, $target, $source, $date, $userId) {
            foreach ($project->stages()->where('sequence_order', '<', $target->sequence_order)->get() as $stage) {
                if ($stage->status === 'Completed') {
                    continue;
                }
                $wasActive = $stage->status === 'In Progress';
                $stage->update(['status' => 'Completed']);
                if ($wasActive) {
                    $this->autoFill($project, $stage, 'completed', $date, $source, $userId);
                }
            }

            $changes = ['status' => 'In Progress'];
            if ($target->end_source !== self::MANUAL) {
                $changes += ['actual_end_at' => null, 'end_source' => null];
            }
            $target->update($changes);
            $this->autoFill($project, $target->fresh(), 'started', $date, $source, $userId);

            $this->resetLater($project, (int) $target->sequence_order);
        });
    }

    /** Every open stage done (case Granted / Completed, or a source engagement closed). */
    public function completeAll(Project $project, string $source, ?Carbon $date = null, ?int $userId = null): void
    {
        $date ??= Carbon::now();
        foreach ($project->stages()->where('status', '!=', 'Completed')->get() as $stage) {
            $wasActive = $stage->status === 'In Progress';
            $stage->update(['status' => 'Completed']);
            if ($wasActive) {
                $this->autoFill($project, $stage, 'completed', $date, $source, $userId);
            }
        }
    }

    /** A docket event proves a stage (and every earlier open one) done on the event date. */
    public function completeThrough(Project $project, ProjectStage $stage, Carbon $date, string $source = 'docket'): void
    {
        foreach ($project->stages()->where('sequence_order', '<=', $stage->sequence_order)->where('status', '!=', 'Completed')->get() as $open) {
            $wasActive = $open->status === 'In Progress' || $open->id === $stage->id;
            $open->update(['status' => 'Completed']);
            if ($wasActive) {
                $this->autoFill($project, $open, 'completed', $date, $source, null);
            }
        }
        $next = $project->stages()->where('sequence_order', '>', $stage->sequence_order)->first();
        if ($next && $next->status === 'Pending') {
            $next->update(['status' => 'In Progress']);
            $this->autoFill($project, $next->fresh(), 'started', $date, $source, null);
        }
    }

    /** "Record workflow event": a stage started or completed on a date, entered by hand. */
    public function record(Project $project, ProjectStage $stage, string $event, Carbon $date, ?string $notes, ?int $userId, string $source = self::MANUAL): void
    {
        DB::transaction(function () use ($project, $stage, $event, $date, $notes, $userId, $source) {
            if ($event === 'started') {
                $stage->update(['actual_start_at' => $date, 'start_source' => self::MANUAL]);
                if ($stage->status === 'Pending') {
                    // Starting a stage means the earlier ones are done and later ones not yet.
                    $project->stages()->where('sequence_order', '<', $stage->sequence_order)->where('status', '!=', 'Completed')->update(['status' => 'Completed']);
                    $project->stages()->where('sequence_order', '>', $stage->sequence_order)->where('status', 'In Progress')->update(['status' => 'Pending']);
                    $stage->update(['status' => 'In Progress']);
                }
            } else {
                $stage->update(['actual_end_at' => $date, 'end_source' => self::MANUAL, 'status' => 'Completed']);
                $project->stages()->where('sequence_order', '<', $stage->sequence_order)->where('status', '!=', 'Completed')->update(['status' => 'Completed']);
                $this->advanceAfter($project, (int) $stage->sequence_order);
            }
            $this->log($project, $stage, $event, $date, $source, $notes, $userId);
        });
    }

    /**
     * Back-log several stages at once: [{stage_id, completed_on?, started_on?}]. Stages up to
     * the last one completed become Completed; the next becomes current unless the case is done.
     *
     * @param  array<int, array{stage_id: int, completed_on?: ?string, started_on?: ?string}>  $rows
     */
    public function backlog(Project $project, array $rows, ?int $userId): int
    {
        $count = 0;
        DB::transaction(function () use ($project, $rows, $userId, &$count) {
            $stages = $project->stages()->get()->keyBy('id');
            $lastDone = -1;
            foreach ($rows as $row) {
                $stage = $stages->get((int) $row['stage_id']);
                if (! $stage) {
                    continue;
                }
                if (! empty($row['started_on'])) {
                    $date = Carbon::parse($row['started_on']);
                    $stage->update(['actual_start_at' => $date, 'start_source' => self::MANUAL]);
                    $this->log($project, $stage, 'started', $date, 'bulk', null, $userId);
                    $count++;
                }
                if (! empty($row['completed_on'])) {
                    $date = Carbon::parse($row['completed_on']);
                    $stage->update(['actual_end_at' => $date, 'end_source' => self::MANUAL, 'status' => 'Completed']);
                    $this->log($project, $stage, 'completed', $date, 'bulk', null, $userId);
                    $lastDone = max($lastDone, (int) $stage->sequence_order);
                    $count++;
                }
            }
            if ($lastDone >= 0) {
                $project->stages()->where('sequence_order', '<', $lastDone)->where('status', '!=', 'Completed')->update(['status' => 'Completed']);
                $this->advanceAfter($project, $lastDone);
            }
        });

        return $count;
    }

    /** After stage $order completes: the next open stage becomes current (unless the case is done). */
    private function advanceAfter(Project $project, int $order): void
    {
        if (in_array($project->status, ['Granted', 'Completed', 'Closed'], true)) {
            return;
        }
        if ($project->stages()->where('status', 'In Progress')->where('sequence_order', '>', $order)->exists()) {
            return;
        }
        $project->stages()->where('sequence_order', '<', $order)->where('status', 'In Progress')->update(['status' => 'Completed']);
        $next = $project->stages()->where('sequence_order', '>', $order)->where('status', '!=', 'Completed')->first();
        $next?->update(['status' => 'In Progress']);
    }

    /** Removing a log line clears the date it set (when the stage still carries that date). */
    public function forget(ProjectStageEvent $event): void
    {
        DB::transaction(function () use ($event) {
            $stage = $event->stage;
            if ($stage) {
                $column = $event->event === 'started' ? 'actual_start_at' : 'actual_end_at';
                if ($stage->{$column} && $stage->{$column}->isSameDay($event->event_date)) {
                    $stage->update([$column => null, ($event->event === 'started' ? 'start_source' : 'end_source') => null]);
                }
            }
            $event->delete();
        });
    }

    /**
     * Each stage with its effective start: its own, else the previous stage's completion.
     *
     * @return Collection<int, array{stage: ProjectStage, effective_start: ?Carbon, start_from_previous: bool}>
     */
    public function withEffectiveStarts(Collection $stages): Collection
    {
        $previousEnd = null;

        return $stages->sortBy('sequence_order')->values()->map(function (ProjectStage $stage) use (&$previousEnd) {
            $own = $stage->actual_start_at;
            $fromPrevious = $own === null && $previousEnd !== null && $stage->status !== 'Pending';
            $row = ['stage' => $stage, 'effective_start' => $own ?? ($fromPrevious ? $previousEnd : null), 'start_from_previous' => $fromPrevious];
            $previousEnd = $stage->actual_end_at ?? null;

            return $row;
        });
    }

    /**
     * Dates the case already knows, offered for back-logging (never written until accepted):
     * the IDF received date for the first stage, docket events for the stages they complete,
     * the filing date for filing stages, the grant date for grant / issue stages.
     *
     * @return array<int, array{stage_id: int, event: string, date: string, reason: string}>
     */
    public function suggestions(Project $project): array
    {
        $stages = $project->stages()->get();
        $app = $project->patentApplication;
        $events = DocketEvent::where(function ($q) use ($project, $app) {
            $q->where('project_id', $project->id);
            if ($app) {
                $q->orWhere('patent_application_id', $app->id);
            }
        })->orderBy('event_date')->get();
        $labels = \App\Support\DocketRules::EVENT_TYPES;
        $out = [];
        $add = function (ProjectStage $stage, string $event, $date, string $reason) use (&$out) {
            $column = $event === 'started' ? 'actual_start_at' : 'actual_end_at';
            if (! $date || $stage->{$column} || $stage->{($event === 'started' ? 'start_source' : 'end_source')} === self::MANUAL) {
                return;
            }
            $key = "{$stage->id}-{$event}";
            $out[$key] ??= ['stage_id' => $stage->id, 'event' => $event, 'date' => Carbon::parse($date)->toDateString(), 'reason' => $reason];
        };

        if ($first = $stages->first()) {
            if ($project->idf_received_date) {
                $add($first, 'started', $project->idf_received_date, 'IDF received date');
            } elseif ($project->start_date) {
                $add($first, 'started', $project->start_date, 'Case start date');
            }
        }
        foreach ($stages as $stage) {
            foreach ((array) ($stage->gate_criteria['advances_on_event_types'] ?? []) as $type) {
                $event = $events->firstWhere('event_type', $type);
                if ($event) {
                    $add($stage, 'completed', $event->event_date, 'Docket event: '.($labels[$type] ?? $type));
                }
            }
            $name = strtolower($stage->stage_name);
            if ($app?->filing_date && (str_contains($name, 'filed') || str_contains($name, 'filing receipt') || str_contains($name, 'uspto filing') || str_contains($name, 'forms and filing'))) {
                $add($stage, 'completed', $app->filing_date, 'Application filing date');
            }
            if ($app?->grant_date && (str_contains($name, 'grant') || str_contains($name, 'patent issued'))) {
                $add($stage, 'completed', $app->grant_date, 'Grant date');
            }
        }

        return array_values($out);
    }
}
