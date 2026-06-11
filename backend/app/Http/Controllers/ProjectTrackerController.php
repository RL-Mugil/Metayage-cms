<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\ProjectStage;
use App\Models\TrackerCircle;
use App\Models\TrackerRow;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Support\Carbon;
use Inertia\Inertia;

class ProjectTrackerController extends Controller implements HasMiddleware
{
    /**
     * The tracker is internal practice management. Client-portal accounts
     * must not read or mutate any tracker row, circle, or analytics —
     * this guard covers every action on the controller.
     */
    public static function middleware(): array
    {
        return [
            function (Request $request, $next) {
                if ($request->user() && $request->user()->role === 'client') {
                    return response()->json(['message' => 'Forbidden'], 403);
                }
                return $next($request);
            },
        ];
    }

    // Status → percentage_of_completion mapping (locked)
    const STATUS_COMPLETION = [
        'Not Started'                             => 0,
        'Allocated'                               => 5,
        'Conducting search'                       => 10,
        'Shared search report'                    => 15,
        'Shared key features'                     => 15,
        'Awaiting the draft from the client'      => 20,
        'To schedule call'                        => 20,
        'Scheduled call with client'              => 25,
        'shared draft & drawings'                 => 30,
        'Patent Drafting'                         => 35,
        'To share the claims with client'         => 45,
        'Internal Review'                         => 55,
        'received Comments from client - to Update' => 60,
        'Client Review'                           => 65,
        'Awaiting feedback'                       => 70,
        'Awaiting signed forms'                   => 75,
        'To file'                                 => 80,
        'Awaiting payment'                        => 90,
        'On Hold'                                 => null, // preserve current
        'Completed'                               => 100,
    ];

    // Status → pipeline stage mapping
    const STATUS_STAGE = [
        'Not Started'                             => 'Intake',
        'Allocated'                               => 'Intake',
        'Conducting search'                       => 'Intake',
        'Shared search report'                    => 'Intake',
        'Shared key features'                     => 'Intake',
        'Awaiting the draft from the client'      => 'Intake',
        'To schedule call'                        => 'Intake',
        'Scheduled call with client'              => 'Intake',
        'Patent Drafting'                         => 'Drafting',
        'shared draft & drawings'                 => 'Drafting',
        'To share the claims with client'         => 'Drafting',
        'Internal Review'                         => 'Drafting',
        'Client Review'                           => 'Drafting',
        'Awaiting feedback'                       => 'Drafting',
        'received Comments from client - to Update' => 'Drafting',
        'Awaiting signed forms'                   => 'Filing',
        'To file'                                 => 'Filing',
        'Awaiting payment'                        => 'Examination',
        'On Hold'                                 => null, // don't change stage
        'Completed'                               => 'Granted',
    ];

    public function inertiaIndex()
    {
        return Inertia::render('ProjectTracker');
    }

    public function circles()
    {
        $circles = TrackerCircle::with(['members:id,name,email,role'])->get();
        return response()->json($circles);
    }

    public function rows(Request $request)
    {
        $circle = TrackerCircle::where('slug', $request->circle ?? 'a')->firstOrFail();
        $rows = TrackerRow::where('circle_id', $circle->id)
            ->orderBy('sort_order')
            ->orderBy('created_at')
            ->get();
        return response()->json($rows);
    }

    // Lightweight project list for docket combobox
    public function projects(Request $request)
    {
        $q = $request->q ?? '';
        $query = Project::with(['client:id,company_name', 'partner:id,name', 'manager:id,name', 'patentEngineer:id,name'])
            ->whereNull('deleted_at');

        if ($q) {
            $query->where(function ($sub) use ($q) {
                $sub->where('docket_number', 'ilike', "%{$q}%")
                    ->orWhere('project_code', 'ilike', "%{$q}%")
                    ->orWhereHas('client', fn ($c) => $c->where('company_name', 'ilike', "%{$q}%"));
            });
        }

        return response()->json(
            $query->orderBy('created_at', 'desc')
                ->limit(100)
                ->get()
                ->map(fn ($p) => [
                    'id'            => $p->id,
                    'project_code'  => $p->project_code,
                    'docket_number' => $p->docket_number,
                    'client_name'   => $p->client?->company_name,
                    'partner_name'  => $p->partner?->name,
                    'manager_name'  => $p->manager?->name,
                    'engineer_name' => $p->patentEngineer?->name,
                    'start_date'    => $p->start_date?->toDateString(),
                    'record_type'   => $p->case_type,
                ])
        );
    }

    public function createRow(Request $request)
    {
        $circle = TrackerCircle::where('slug', $request->circle_slug ?? 'a')->firstOrFail();

        $row = TrackerRow::create([
            'circle_id'               => $circle->id,
            'status'                  => null,
            'payment_status'          => null,
            'percentage_of_completion'=> 0,
            'sort_order'              => TrackerRow::where('circle_id', $circle->id)->max('sort_order') + 1,
        ]);

        return response()->json($row, 201);
    }

    public function updateRow(Request $request, $id)
    {
        $row = TrackerRow::findOrFail($id);

        $allowed = [
            'project_id', 'docket_number', 'client_name', 'record_type',
            'pcm', 'scm', 'pr', 'project_start_date', 'status',
            'delivery_due_date', 'payment_status', 'uin', 'sort_order',
        ];

        $data = $request->only($allowed);

        // Normalize empty strings to null
        foreach ($data as $k => $v) {
            if ($v === '' || $v === 'null') {
                $data[$k] = null;
            }
        }

        // Auto-compute % completion from status (locked)
        if (isset($data['status'])) {
            $pct = self::STATUS_COMPLETION[$data['status']] ?? null;
            if ($pct !== null) {
                $data['percentage_of_completion'] = $pct;
            }
            // null means On Hold — don't touch percentage
        }

        $row->update($data);

        // Sync pipeline stage on linked project
        if (isset($data['status'])) {
            $projectId = $row->project_id;
            if ($projectId) {
                $stage = self::STATUS_STAGE[$data['status']] ?? null;
                if ($stage) {
                    $this->syncProjectStage($projectId, $stage);
                }
            }
        }

        return response()->json($row->fresh());
    }

    public function deleteRow($id)
    {
        TrackerRow::findOrFail($id)->delete();
        return response()->json(['message' => 'Deleted']);
    }

    public function trackerAnalytics(Request $request)
    {
        $rows = TrackerRow::all();
        $today = now()->startOfDay();

        $total   = $rows->count();
        $overdue = $rows->filter(fn($r) => $r->delivery_due_date && $r->delivery_due_date->lt($today))->count();

        // Payment status breakdown
        $payment = ['Paid' => 0, 'Partial' => 0, 'Pending' => 0, 'Not Set' => 0];
        foreach ($rows as $r) {
            $k = $r->payment_status ?? 'Not Set';
            $payment[$k] = ($payment[$k] ?? 0) + 1;
        }

        // By record type
        $byType = $rows->groupBy('record_type')->map->count()->sortDesc()
            ->map(fn($c, $t) => ['type' => $t ?? 'Unknown', 'count' => $c])->values();

        // By status (top 10)
        $byStatus = $rows->filter(fn($r) => $r->status)
            ->groupBy('status')->map->count()->sortDesc()->take(10)
            ->map(fn($c, $s) => ['status' => $s, 'count' => $c])->values();

        // Workload per team member (from PCM/SCM/PR name strings)
        $workload = [];
        foreach ($rows as $r) {
            foreach (['pcm' => 'PCM', 'scm' => 'SCM', 'pr' => 'PR'] as $field => $role) {
                $name = trim($r->$field ?? '');
                if ($name === '') continue;
                $first = explode(' ', $name)[0];
                if (!isset($workload[$first])) $workload[$first] = ['name' => $first, 'PCM' => 0, 'SCM' => 0, 'PR' => 0, 'total' => 0];
                $workload[$first][$role]++;
                $workload[$first]['total']++;
            }
        }
        usort($workload, fn($a, $b) => $b['total'] - $a['total']);

        return response()->json([
            'total_cases'    => $total,
            'overdue'        => $overdue,
            'on_time_rate'   => $total > 0 ? round((($total - $overdue) / $total) * 100) : 100,
            'payment'        => array_map(fn($k, $v) => ['label' => $k, 'value' => $v], array_keys($payment), array_values($payment)),
            'by_record_type' => $byType,
            'by_status'      => $byStatus,
            'workload'       => array_values($workload),
        ]);
    }

    public function calendarEvents(Request $request)
    {
        $user = $request->user();
        $isAdmin = in_array($user->role, ['super_admin', 'admin']);

        $query = TrackerRow::whereNotNull('delivery_due_date');

        if (!$isAdmin) {
            $firstName = strtolower(explode(' ', trim($user->name))[0]);
            $query->where(function ($q) use ($firstName) {
                $q->whereRaw('LOWER(pcm) LIKE ?', ["%{$firstName}%"])
                  ->orWhereRaw('LOWER(scm) LIKE ?', ["%{$firstName}%"])
                  ->orWhereRaw('LOWER(pr) LIKE ?',  ["%{$firstName}%"]);
            });
        }

        $rows = $query->orderBy('delivery_due_date')->get();

        return response()->json($rows->map(function ($r) use ($user, $isAdmin) {
            $myRole = null;
            if (!$isAdmin) {
                $first = strtolower(explode(' ', trim($user->name))[0]);
                if ($r->pcm && str_contains(strtolower($r->pcm), $first))      $myRole = 'PCM';
                elseif ($r->scm && str_contains(strtolower($r->scm), $first))  $myRole = 'SCM';
                elseif ($r->pr  && str_contains(strtolower($r->pr),  $first))  $myRole = 'PR';
            }
            return [
                'id'                       => $r->id,
                'docket_number'            => $r->docket_number,
                'client_name'              => $r->client_name,
                'record_type'              => $r->record_type,
                'delivery_due_date'        => $r->delivery_due_date?->toDateString(),
                'status'                   => $r->status,
                'pcm'                      => $r->pcm,
                'scm'                      => $r->scm,
                'pr'                       => $r->pr,
                'percentage_of_completion' => $r->percentage_of_completion,
                'my_role'                  => $myRole,
            ];
        }));
    }

    public function addMember(Request $request, $id)
    {
        $circle = TrackerCircle::findOrFail($id);
        $request->validate(['user_id' => 'required|exists:users,id']);
        $circle->members()->syncWithoutDetaching([$request->user_id]);
        return response()->json($circle->load('members:id,name,email,role'));
    }

    public function removeMember($id, $userId)
    {
        $circle = TrackerCircle::findOrFail($id);
        $circle->members()->detach($userId);
        return response()->json($circle->load('members:id,name,email,role'));
    }

    private function syncProjectStage(int $projectId, string $stageName): void
    {
        // Auto-seed pipeline stages if project has none yet
        $hasStages = ProjectStage::where('project_id', $projectId)->exists();
        if (!$hasStages) {
            $defaultStages = ["Intake", "Drafting", "Filing", "Examination", "Object received", "Granted", "Renewal"];
            foreach ($defaultStages as $index => $name) {
                ProjectStage::create([
                    'project_id'     => $projectId,
                    'stage_name'     => $name,
                    'status'         => $index === 0 ? 'In Progress' : 'Pending',
                    'sequence_order' => $index,
                    'duration_days'  => 15,
                    'due_date'       => \Illuminate\Support\Carbon::now()->addDays(($index + 1) * 15),
                ]);
            }
        }

        $targetStage = ProjectStage::where('project_id', $projectId)
            ->where('stage_name', $stageName)
            ->first();

        if (!$targetStage) return;

        ProjectStage::where('project_id', $projectId)
            ->where('sequence_order', '<', $targetStage->sequence_order)
            ->update(['status' => 'Completed', 'actual_end_at' => Carbon::now()]);

        $targetStage->update([
            'status'           => 'In Progress',
            'actual_start_at'  => Carbon::now(),
        ]);

        ProjectStage::where('project_id', $projectId)
            ->where('sequence_order', '>', $targetStage->sequence_order)
            ->update(['status' => 'Pending', 'actual_start_at' => null, 'actual_end_at' => null]);
    }
}
