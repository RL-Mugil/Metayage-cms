<?php

namespace App\Http\Controllers;

use App\Models\DocketDeadline;
use App\Models\Project;
use App\Support\DocketRules;
use Carbon\Carbon;
use Illuminate\Http\Request;

class DocketController extends Controller
{
    /**
     * Events, deadlines and renewal schedule for one project's application.
     */
    public function show(Request $request, $projectId)
    {
        $project = Project::with('patentApplication.renewals')->findOrFail($projectId);
        $app = $project->patentApplication;

        $deadlines = DocketDeadline::with('event')
            ->where(function ($q) use ($project, $app) {
                $q->where('project_id', $project->id);
                if ($app) {
                    $q->orWhere('patent_application_id', $app->id);
                }
            })
            ->orderByRaw("CASE WHEN status = 'Open' THEN 0 ELSE 1 END")
            ->orderBy('due_date')
            ->get();

        $events = \App\Models\DocketEvent::with('creator:id,name')
            ->where(function ($q) use ($project, $app) {
                $q->where('project_id', $project->id);
                if ($app) {
                    $q->orWhere('patent_application_id', $app->id);
                }
            })
            ->orderByDesc('event_date')
            ->get();

        return response()->json([
            'application' => $app,
            'events'      => $events,
            'deadlines'   => $deadlines,
            'renewals'    => $app?->renewals ?? [],
            'event_types' => DocketRules::EVENT_TYPES,
        ]);
    }

    /**
     * Record a docket event; statutory deadlines are generated automatically.
     */
    public function storeEvent(Request $request, $projectId)
    {
        if (in_array($request->user()->role, ['client', 'client_admin'], true)) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $validated = $request->validate([
            'event_type' => 'required|string|in:' . implode(',', array_keys(DocketRules::EVENT_TYPES)),
            'event_date' => 'required|date',
            'notes'      => 'nullable|string|max:2000',
        ]);

        $project = Project::findOrFail($projectId);

        $event = DocketRules::recordEvent(
            $validated['event_type'],
            Carbon::parse($validated['event_date']),
            $project->id,
            $project->patent_application_id,
            $validated['notes'] ?? null,
            $request->user()->id
        );

        return response()->json($event->load('deadlines'), 201);
    }

    /**
     * Complete / waive / reopen a deadline.
     */
    public function updateDeadline(Request $request, $deadlineId)
    {
        if (in_array($request->user()->role, ['client', 'client_admin'], true)) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $validated = $request->validate([
            'status' => 'required|string|in:Open,Completed,Missed,Waived',
            'notes'  => 'nullable|string|max:2000',
        ]);

        $deadline = DocketDeadline::findOrFail($deadlineId);
        $deadline->update([
            'status'       => $validated['status'],
            'completed_at' => $validated['status'] === 'Completed' ? now() : null,
            'notes'        => $validated['notes'] ?? $deadline->notes,
        ]);

        return response()->json($deadline);
    }

    /**
     * Mark a renewal year paid / waived.
     */
    public function updateRenewal(Request $request, $renewalId)
    {
        if (in_array($request->user()->role, ['client', 'client_admin'], true)) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $validated = $request->validate([
            'status' => 'required|string|in:Unpaid,Paid,Waived,Lapsed',
        ]);

        $renewal = \App\Models\RenewalSchedule::findOrFail($renewalId);
        $renewal->update([
            'status'  => $validated['status'],
            'paid_at' => $validated['status'] === 'Paid' ? now() : null,
        ]);

        return response()->json($renewal);
    }

    /**
     * Firm-wide upcoming deadlines (docketing dashboard feed).
     */
    public function upcoming(Request $request)
    {
        $days = min((int) $request->input('days', 90), 365);

        $deadlines = DocketDeadline::with(['project:id,docket_number,project_name,client_id', 'project.client:id,company_name,legal_name'])
            ->where('status', 'Open')
            ->whereDate('due_date', '<=', now()->addDays($days))
            ->orderBy('due_date')
            ->limit(200)
            ->get();

        return response()->json($deadlines);
    }
}
