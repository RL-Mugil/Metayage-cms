<?php

namespace App\Http\Controllers;

use App\Models\DocketDeadline;
use App\Models\Project;
use App\Models\ProjectStage;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Dashboard → Team: workload per person (PCM / SCM / PR on active cases, with overdue
 * deadlines), a deadline heat-map for the next 26 weeks, and — only when a super admin has
 * switched it on — a leaderboard of stages completed and deadlines met this month.
 */
class TeamInsightsController extends Controller
{
    private const ROLES = ['super_admin', 'partner', 'director', 'manager', 'galvanizer', 'associate', 'paralegal'];

    private const ACTIVE = ['Open', 'In Progress', 'On Hold'];

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless(in_array($user->role, self::ROLES, true), 403);
        $projects = $this->scope($user)->whereIn('status', self::ACTIVE)
            ->get(['id', 'assigned_manager_id', 'secondary_manager_id', 'patent_engineer_id']);
        $ids = $projects->pluck('id');
        $today = now()->startOfDay();
        $overdueBy = DocketDeadline::whereIn('project_id', $ids)->where('status', 'Open')->whereDate('due_date', '<', $today)
            ->selectRaw('project_id, COUNT(*) as n')->groupBy('project_id')->pluck('n', 'project_id');

        $people = [];
        foreach (['assigned_manager_id' => 'pcm', 'secondary_manager_id' => 'scm', 'patent_engineer_id' => 'pr'] as $column => $key) {
            foreach ($projects as $p) {
                if (! $p->{$column}) {
                    continue;
                }
                $row = &$people[$p->{$column}];
                $row ??= ['pcm' => 0, 'scm' => 0, 'pr' => 0, 'overdue' => 0, 'cases' => []];
                $row[$key]++;
                $row['cases'][$p->id] = true;
                unset($row);
            }
        }
        $names = User::whereIn('id', array_keys($people))->pluck('name', 'id');
        $workload = collect($people)->map(function ($row, $id) use ($overdueBy, $names) {
            $cases = array_keys($row['cases']);

            return ['user_id' => $id, 'name' => $names[$id] ?? 'Unknown', 'pcm' => $row['pcm'], 'scm' => $row['scm'], 'pr' => $row['pr'],
                'total' => count($cases), 'overdue' => (int) collect($cases)->sum(fn ($c) => $overdueBy[$c] ?? 0)];
        })->sortByDesc('total')->values();

        $heat = DocketDeadline::whereIn('project_id', $ids)->where('status', 'Open')
            ->whereBetween('due_date', [$today->toDateString(), $today->copy()->addWeeks(26)->toDateString()])
            ->selectRaw('DATE(due_date) as day, COUNT(*) as n')->groupBy('day')->pluck('n', 'day')
            ->mapWithKeys(fn ($n, $day) => [Carbon::parse($day)->toDateString() => (int) $n]);

        return response()->json([
            'workload' => $workload,
            'heatmap' => ['start' => $today->toDateString(), 'days' => $heat, 'overdue' => (int) $overdueBy->sum()],
            'leaderboard' => FeatureSwitchController::on('leaderboard_enabled') ? $this->leaderboard($ids) : null,
        ]);
    }

    /** Active cases one person works on (for the workload drill-down). */
    public function cases(Request $request, int $userId): JsonResponse
    {
        $user = $request->user();
        abort_unless(in_array($user->role, self::ROLES, true), 403);
        $rows = $this->scope($user)->whereIn('status', self::ACTIVE)
            ->where(fn ($q) => $q->where('assigned_manager_id', $userId)->orWhere('secondary_manager_id', $userId)->orWhere('patent_engineer_id', $userId))
            ->with('client:id,legal_name,company_name')->orderBy('hard_deadline')->limit(500)
            ->get(['id', 'docket_number', 'project_name', 'client_id', 'status', 'hard_deadline', 'assigned_manager_id', 'secondary_manager_id', 'patent_engineer_id']);

        return response()->json($rows->map(fn ($p) => [
            'id' => $p->id, 'docket_number' => $p->docket_number, 'project_name' => $p->project_name,
            'client' => $p->client?->legal_name ?: $p->client?->company_name, 'status' => $p->status,
            'hard_deadline' => $p->hard_deadline ? Carbon::parse($p->hard_deadline)->toDateString() : null,
            'as' => collect(['PCM' => $p->assigned_manager_id, 'SCM' => $p->secondary_manager_id, 'PR' => $p->patent_engineer_id])
                ->filter(fn ($id) => (int) $id === $userId)->keys()->implode(' / '),
        ])->values());
    }

    /** @return array<int, array<string, mixed>> */
    private function leaderboard($projectIds): array
    {
        $start = now()->startOfMonth();
        $stages = ProjectStage::whereIn('project_id', $projectIds)->where('status', 'Completed')->where('actual_end_at', '>=', $start)
            ->with('project:id,assigned_manager_id')->get(['project_id', 'owner_id']);
        $met = DocketDeadline::whereIn('project_id', $projectIds)->where('status', 'Completed')->where('completed_at', '>=', $start)
            ->with('project:id,assigned_manager_id')->get(['project_id', 'due_date', 'completed_at']);

        $board = [];
        foreach ($stages as $s) {
            $who = $s->owner_id ?: $s->project?->assigned_manager_id;
            if ($who) {
                $board[$who]['stages'] = ($board[$who]['stages'] ?? 0) + 1;
            }
        }
        foreach ($met as $d) {
            $who = $d->project?->assigned_manager_id;
            if ($who && Carbon::parse($d->completed_at)->startOfDay()->lte(Carbon::parse($d->due_date))) {
                $board[$who]['deadlines'] = ($board[$who]['deadlines'] ?? 0) + 1;
            }
        }
        $names = User::whereIn('id', array_keys($board))->pluck('name', 'id');

        return collect($board)->map(fn ($r, $id) => ['user_id' => $id, 'name' => $names[$id] ?? 'Unknown',
            'stages' => $r['stages'] ?? 0, 'deadlines' => $r['deadlines'] ?? 0, 'score' => ($r['stages'] ?? 0) + ($r['deadlines'] ?? 0)])
            ->sortByDesc('score')->take(10)->values()->all();
    }

    private function scope(User $user): Builder
    {
        $query = Project::query();
        if ($user->isGalvanizer()) {
            $user->applyProjectScope($query);
        } elseif (in_array($user->role, ['associate', 'paralegal'], true)) {
            $query->assignedTo($user->id);
        }

        return $query;
    }
}
