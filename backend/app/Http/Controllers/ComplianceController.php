<?php

namespace App\Http\Controllers;

use App\Http\PaginationHelper;
use App\Models\ComplianceItem;
use App\Models\Reminder;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class ComplianceController extends Controller
{
    private const ALLOWED_ROLES = ['super_admin', 'partner', 'manager'];

    private function denyClients(Request $request): ?\Illuminate\Http\JsonResponse
    {
        if (! in_array($request->user()->role, self::ALLOWED_ROLES)) {
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

    public function stats(Request $request)
    {
        if ($deny = $this->denyClients($request)) return $deny;

        $today = Carbon::today()->toDateString();
        $d30   = Carbon::today()->addDays(30)->toDateString();
        $d31   = Carbon::today()->addDays(31)->toDateString();
        $d75   = Carbon::today()->addDays(75)->toDateString();
        $d76   = Carbon::today()->addDays(76)->toDateString();
        $d150  = Carbon::today()->addDays(150)->toDateString();

        $row = ComplianceItem::where('status', '!=', 'Resolved')
            ->selectRaw("
                SUM(CASE WHEN deadline <= ? THEN 1 ELSE 0 END) as critical,
                SUM(CASE WHEN deadline BETWEEN ? AND ? THEN 1 ELSE 0 END) as at_risk,
                SUM(CASE WHEN deadline BETWEEN ? AND ? THEN 1 ELSE 0 END) as on_track,
                SUM(CASE WHEN deadline > ? THEN 1 ELSE 0 END) as compliant
            ", [$d30, $d31, $d75, $d76, $d150, $d150])
            ->first();

        return response()->json([
            'critical' => (int) ($row?->critical ?? 0),
            'at_risk'  => (int) ($row?->at_risk  ?? 0),
            'on_track' => (int) ($row?->on_track ?? 0),
            'compliant'=> (int) ($row?->compliant ?? 0),
        ]);
    }

    public function index(Request $request)
    {
        if ($deny = $this->denyClients($request)) return $deny;

        $query = ComplianceItem::where('status', '!=', 'Resolved')->orderBy('deadline');

        if ($request->filled('status')) {
            $today = Carbon::today();
            match ($request->status) {
                'Critical' => $query->where('deadline', '<=', $today->copy()->addDays(30)),
                'At Risk'  => $query->whereBetween('deadline', [$today->copy()->addDays(31)->toDateString(), $today->copy()->addDays(75)->toDateString()]),
                'On Track' => $query->whereBetween('deadline', [$today->copy()->addDays(76)->toDateString(), $today->copy()->addDays(150)->toDateString()]),
                'Compliant'=> $query->where('deadline', '>', $today->copy()->addDays(150)),
                default    => null,
            };
        }

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
            $item->assignee    = $validated['assignee'];
            $item->assignee_id = User::where('name', $validated['assignee'])->value('id');
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
                'title' => Str::limit($item->action_required . ' — ' . $item->matter, 250),
                'description' => "{$item->jurisdiction} deadline",
                'category' => 'Deadline',
                'due_date' => $item->deadline,
                'scope' => 'self',
            ]
        );

        return response()->json(['ok' => true, 'reminder_id' => $reminder->id]);
    }
}
