<?php

namespace App\Http\Controllers;

use App\Http\PaginationHelper;
use App\Models\ComplianceItem;
use App\Models\Reminder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class ComplianceController extends Controller
{
    private function denyClients(Request $request): ?\Illuminate\Http\JsonResponse
    {
        if ($request->user()->role === 'client') {
            return response()->json(['message' => 'Forbidden'], 403);
        }
        return null;
    }

    /** Derive alert level from days until deadline. */
    private function alertLevel(int $daysLeft): string
    {
        if ($daysLeft <= 30) return 'Critical';
        if ($daysLeft <= 75) return 'At Risk';
        if ($daysLeft <= 150) return 'On Track';
        return 'Compliant';
    }

    public function index(Request $request)
    {
        if ($deny = $this->denyClients($request)) return $deny;

        $query = ComplianceItem::where('status', '!=', 'Resolved')->orderBy('deadline');
        $result = PaginationHelper::paginate($query, $request, 50);

        $result['data'] = $result['data']->map(function ($i) {
            $daysLeft = (int) Carbon::today()->diffInDays(Carbon::parse($i->deadline), false);
            return [
                'id'           => $i->id,
                'matter'       => $i->matter,
                'type'         => $i->type,
                'jurisdiction' => $i->jurisdiction,
                'deadline'     => $i->deadline->format('Y-m-d'),
                'daysLeft'     => $daysLeft,
                'status'       => $this->alertLevel($daysLeft),
                'action'       => $i->action_required,
                'assignee'     => $i->assignee,
                'assignee_id'  => $i->assignee_id ?? null,
                'notes'        => $i->notes ?? [],
            ];
        });

        return response()->json($result);
    }

    public function update(Request $request, $id)
    {
        if ($deny = $this->denyClients($request)) return $deny;

        $item = ComplianceItem::findOrFail($id);
        $validated = $request->validate([
            'assignee' => 'sometimes|string|max:255',
            'note' => 'sometimes|string|max:2000',
            'resolved' => 'sometimes|boolean',
        ]);

        if (array_key_exists('assignee', $validated)) {
            $item->assignee = $validated['assignee'];
        }
        if (! empty($validated['note'])) {
            $notes = $item->notes ?? [];
            $notes[] = [
                'text' => $validated['note'],
                'by' => $request->user()->name,
                'at' => now()->toDateTimeString(),
            ];
            $item->notes = $notes;
        }
        if (! empty($validated['resolved'])) {
            $item->status = 'Resolved';
            $item->resolved_at = now();
        }
        $item->save();

        return response()->json(['ok' => true]);
    }

    /** Create a reminder for the current user from a compliance deadline. */
    public function remind(Request $request, $id)
    {
        if ($deny = $this->denyClients($request)) return $deny;

        $item = ComplianceItem::findOrFail($id);

        $reminder = Reminder::firstOrCreate(
            ['user_id' => $request->user()->id, 'source' => "compliance:{$item->id}"],
            [
                'title' => $item->action_required . ' — ' . $item->matter,
                'description' => "{$item->jurisdiction} deadline",
                'category' => 'Deadline',
                'due_date' => $item->deadline,
                'scope' => 'self',
            ]
        );

        return response()->json(['ok' => true, 'reminder_id' => $reminder->id]);
    }
}
