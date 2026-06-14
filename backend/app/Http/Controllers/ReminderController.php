<?php

namespace App\Http\Controllers;

use App\Http\PaginationHelper;
use App\Models\Reminder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class ReminderController extends Controller
{
    private function denyClients(Request $request): ?\Illuminate\Http\JsonResponse
    {
        if ($request->user()->role === 'client') {
            return response()->json(['message' => 'Forbidden'], 403);
        }
        return null;
    }

    private function section(Carbon $due): string
    {
        $today = Carbon::today();
        if ($due->lte($today)) return 'today';
        if ($due->lte($today->copy()->addDays(7))) return 'week';
        return 'upcoming';
    }

    public function index(Request $request)
    {
        if ($deny = $this->denyClients($request)) return $deny;

        $user = $request->user();
        $query = Reminder::where(fn ($q) => $q->where('user_id', $user->id)->orWhere('scope', 'team'))
            ->orderBy('due_date');

        $result = PaginationHelper::paginate($query, $request);
        $result['data'] = $result['data']->map(fn ($r) => [
            'id'         => $r->id,
            'title'      => $r->title,
            'description'=> $r->description ?? '',
            'category'   => $r->category,
            'dueDate'    => $r->due_date->format('Y-m-d'),
            'dueTime'    => $r->due_time,
            'assignedTo' => $r->scope === 'team' ? 'Team' : ($r->user_id === $user->id ? 'You' : 'Team'),
            'completed'  => $r->completed,
            'section'    => $this->section(Carbon::parse($r->due_date)),
        ]);

        return response()->json($result);
    }

    public function store(Request $request)
    {
        if ($deny = $this->denyClients($request)) return $deny;

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string|max:2000',
            'category' => 'required|in:Deadline,Meeting,Follow-up,Renewal',
            'due_date' => 'required|date',
            'due_time' => 'nullable|date_format:H:i',
            'scope' => 'required|in:self,team',
        ]);

        $reminder = Reminder::create($validated + ['user_id' => $request->user()->id]);

        return response()->json(['ok' => true, 'id' => $reminder->id], 201);
    }

    public function update(Request $request, $id)
    {
        if ($deny = $this->denyClients($request)) return $deny;

        $user = $request->user();
        $reminder = Reminder::where('id', $id)
            ->where(fn ($q) => $q->where('user_id', $user->id)->orWhere('scope', 'team'))
            ->firstOrFail();

        $validated = $request->validate(['completed' => 'required|boolean']);
        $reminder->update($validated);

        return response()->json(['ok' => true]);
    }
}
