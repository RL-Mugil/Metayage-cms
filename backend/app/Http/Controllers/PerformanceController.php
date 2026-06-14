<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\PerformanceFeedback360;
use App\Models\PerformanceGoal;
use App\Models\PerformanceReview;
use Illuminate\Http\Request;

class PerformanceController extends Controller
{
    private const READ_ROLES = ['super_admin', 'partner', 'manager', 'hr'];
    private const WRITE_ROLES = ['super_admin', 'partner', 'manager', 'hr'];

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

        return response()->json([
            'reviews' => PerformanceReview::orderBy('id')->get()->map(fn ($r) => [
                'id' => $r->id,
                'employee' => $r->employee,
                'reviewer' => $r->reviewer,
                'period' => $r->period,
                'rating' => (float) $r->rating,
                'status' => $r->status,
            ]),
            'goals' => PerformanceGoal::orderBy('id')->get()->map(fn ($g) => [
                'id' => $g->id,
                'title' => $g->title,
                'employee' => $g->employee,
                'due' => $g->due_label,
                'progress' => $g->progress,
                'status' => $g->status,
            ]),
            'feedback360' => PerformanceFeedback360::orderBy('id')->get()->map(fn ($f) => [
                'id' => $f->id,
                'from' => $f->from_name,
                'to' => $f->to_name,
                'sent' => $f->sent_label,
                'status' => $f->status,
            ]),
        ]);
    }

    public function submitReview(Request $request, $id)
    {
        if ($deny = $this->gate($request, self::WRITE_ROLES)) return $deny;

        $review = PerformanceReview::findOrFail($id);
        if ($review->status === 'Completed') {
            return response()->json(['message' => 'Review already completed'], 422);
        }

        $validated = $request->validate([
            'scores' => 'required|array',
            'scores.technical' => 'required|integer|min:1|max:5',
            'scores.communication' => 'required|integer|min:1|max:5',
            'scores.teamwork' => 'required|integer|min:1|max:5',
            'scores.leadership' => 'required|integer|min:1|max:5',
            'scores.initiative' => 'required|integer|min:1|max:5',
            'comments' => 'nullable|string|max:5000',
        ]);

        $review->update([
            'scores' => $validated['scores'],
            'comments' => $validated['comments'] ?? null,
            'rating' => round(array_sum($validated['scores']) / count($validated['scores']), 1),
            'status' => 'Completed',
        ]);

        return response()->json(['ok' => true, 'rating' => (float) $review->rating]);
    }

    public function storeGoal(Request $request)
    {
        if ($deny = $this->gate($request, self::WRITE_ROLES)) return $deny;

        $validated = $request->validate([
            'employee_id' => 'required|exists:employees,id',
            'title'       => 'required|string|max:255',
            'due_label'   => 'nullable|string|max:50',
            'status'      => 'nullable|in:In Progress,Completed,Missed',
        ]);

        $emp = Employee::findOrFail($validated['employee_id']);
        $goal = PerformanceGoal::create([
            'employee_id' => $emp->id,
            'employee'    => $emp->full_name,
            'title'       => $validated['title'],
            'due_label'   => $validated['due_label'] ?? 'TBD',
            'progress'    => 0,
            'status'      => $validated['status'] ?? 'In Progress',
        ]);

        return response()->json(['ok' => true, 'goal' => [
            'id'       => $goal->id,
            'title'    => $goal->title,
            'progress' => $goal->progress,
            'status'   => $goal->status,
        ]], 201);
    }

    public function updateGoal(Request $request, $id)
    {
        if ($deny = $this->gate($request, self::WRITE_ROLES)) return $deny;

        $goal = PerformanceGoal::findOrFail($id);
        $validated = $request->validate([
            'progress' => 'sometimes|integer|min:0|max:100',
            'status'   => 'sometimes|in:In Progress,Completed,Missed',
        ]);

        if (isset($validated['progress']) && $validated['progress'] === 100) {
            $validated['status'] = 'Completed';
        }

        $goal->update($validated);

        return response()->json(['ok' => true, 'progress' => $goal->progress, 'status' => $goal->status]);
    }

    public function storeFeedback360(Request $request)
    {
        if ($deny = $this->gate($request, self::WRITE_ROLES)) return $deny;

        $validated = $request->validate([
            'from_name' => 'required|string|max:255',
            'to_name'   => 'required|string|max:255',
            'comments'  => 'nullable|string|max:5000',
            'status'    => 'nullable|in:Pending,Submitted',
        ]);

        $feedback = PerformanceFeedback360::create([
            'from_name'  => $validated['from_name'],
            'to_name'    => $validated['to_name'],
            'comments'   => $validated['comments'] ?? null,
            'sent_label' => now()->format('d M Y'),
            'status'     => $validated['status'] ?? 'Pending',
        ]);

        return response()->json(['ok' => true, 'id' => $feedback->id], 201);
    }
}
