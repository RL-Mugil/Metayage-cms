<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\OffboardingCase;
use App\Models\User;
use Illuminate\Http\Request;

class OffboardingController extends Controller
{
    private const READ_ROLES = ['super_admin', 'partner', 'manager', 'hr'];
    private const WRITE_ROLES = ['super_admin', 'hr'];
    private const CHECKLIST_SIZE = 8;

    private function gate(Request $request, array $roles): ?\Illuminate\Http\JsonResponse
    {
        if (! in_array($request->user()->role, $roles)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }
        return null;
    }

    public function index(Request $request)
    {
        if ($deny = $this->gate($request, self::READ_ROLES)) return $deny;

        $all = OffboardingCase::orderBy('id')->get();

        return response()->json([
            'cases' => $all->where('status', '!=', 'Completed')->values()->map(fn ($c) => [
                'id' => $c->id,
                'employee' => $c->employee,
                'dept' => $c->dept,
                'lastDay' => $c->last_day,
                'exitType' => $c->exit_type,
                'checklist' => $c->checklist,
                'assignedHR' => $c->assigned_hr,
                'status' => $c->status,
            ]),
            'completed' => $all->where('status', 'Completed')->values()->map(fn ($c) => [
                'id' => $c->id,
                'employee' => $c->employee,
                'dept' => $c->dept,
                'lastDay' => $c->last_day,
                'exitType' => $c->exit_type,
                'completedDate' => $c->completed_label,
            ]),
        ]);
    }

    public function store(Request $request)
    {
        if ($deny = $this->gate($request, self::WRITE_ROLES)) return $deny;

        $validated = $request->validate([
            'employee' => 'required|string|max:255',
            'dept' => 'nullable|string|max:50',
            'last_day' => 'required|string|max:30',
            'exit_type' => 'required|in:Resignation,Retirement,Termination',
            'assigned_hr' => 'nullable|string|max:255',
        ]);

        $assignedHrName = ($validated['assigned_hr'] ?? null) ?: $request->user()->name;
        $employee = Employee::where('full_name', $validated['employee'])->first();
        $hrUser   = User::where('name', $assignedHrName)->first();

        $case = OffboardingCase::create([
            'employee'       => $validated['employee'],
            'employee_id'    => $employee?->id,
            'dept'           => ($validated['dept'] ?? null) ?: 'General',
            'last_day'       => $validated['last_day'],
            'exit_type'      => $validated['exit_type'],
            'assigned_hr'    => $assignedHrName,
            'assigned_hr_id' => $hrUser?->id,
            'status'         => 'Scheduled',
            'checklist'      => array_fill(0, self::CHECKLIST_SIZE, false),
        ]);

        return response()->json(['ok' => true, 'id' => $case->id], 201);
    }

    public function updateChecklist(Request $request, $id)
    {
        if ($deny = $this->gate($request, self::WRITE_ROLES)) return $deny;

        $case = OffboardingCase::findOrFail($id);
        $validated = $request->validate([
            'checklist' => 'required|array|size:' . self::CHECKLIST_SIZE,
            'checklist.*' => 'boolean',
        ]);

        $checklist = array_map(fn ($v) => (bool) $v, $validated['checklist']);
        $doneCount = count(array_filter($checklist));

        $case->checklist = $checklist;
        if ($doneCount === self::CHECKLIST_SIZE) {
            $case->status = 'Completed';
            $case->completed_label = now()->format('d M Y');
        } else {
            $case->status = $doneCount > 0 ? 'In Progress' : 'Scheduled';
            $case->completed_label = null;
        }
        $case->save();

        return response()->json(['ok' => true, 'status' => $case->status]);
    }
}
