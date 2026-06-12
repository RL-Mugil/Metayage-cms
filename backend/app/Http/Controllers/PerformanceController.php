<?php

namespace App\Http\Controllers;

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
}
