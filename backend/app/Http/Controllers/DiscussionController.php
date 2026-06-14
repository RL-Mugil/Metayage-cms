<?php

namespace App\Http\Controllers;

use App\Models\DiscussionMessage;
use App\Models\DiscussionThread;
use App\Http\PaginationHelper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DiscussionController extends Controller
{
    private function denyClients(Request $request)
    {
        if ($request->user()->role === 'client') {
            return response()->json(['message' => 'Forbidden'], 403);
        }
        return null;
    }

    public function index(Request $request)
    {
        if ($deny = $this->denyClients($request)) return $deny;

        $user = $request->user();
        $query = DiscussionThread::with(['messages.author:id,name'])->orderByDesc('updated_at');

        if (in_array($user->role, ['associate', 'paralegal'])) {
            $query->whereHas('project', function ($q) use ($user) {
                $q->where('assigned_manager_id', $user->id)
                  ->orWhere('assigned_partner_id', $user->id)
                  ->orWhere('patent_engineer_id', $user->id)
                  ->orWhereHas('tasks', fn ($t) => $t->where('assignee_id', $user->id));
            });
        }
        $paginated = PaginationHelper::paginate($query, $request);

        $paginated['data'] = $paginated['data']->map(fn ($t) => [
            'id'         => $t->id,
            'title'      => $t->title,
            'tag'        => $t->tag ?? 'General',
            'status'     => $t->status,
            'author'     => $t->messages->first()?->author?->name ?? '—',
            'last_reply' => $t->updated_at?->diffForHumans(),
            'messages'   => $t->messages->map(fn ($m) => [
                'id'     => $m->id,
                'author' => $m->author?->name ?? '—',
                'time'   => $m->created_at?->diffForHumans(),
                'text'   => $m->content,
            ]),
        ]);

        return response()->json($paginated);
    }

    public function store(Request $request)
    {
        if ($deny = $this->denyClients($request)) return $deny;

        $validated = $request->validate([
            'title'   => 'required|string|max:255',
            'tag'     => 'nullable|in:General,Project,HR,Finance',
            'message' => 'required|string',
        ]);

        $thread = DB::transaction(function () use ($validated, $request) {
            $thread = DiscussionThread::create([
                'title'  => $validated['title'],
                'tag'    => $validated['tag'] ?? 'General',
                'status' => 'Open',
            ]);
            DiscussionMessage::create([
                'thread_id' => $thread->id,
                'author_id' => $request->user()->id,
                'content'   => $validated['message'],
            ]);
            return $thread;
        });

        return response()->json($thread->load('messages.author:id,name'), 201);
    }

    public function reply(Request $request, $id)
    {
        if ($deny = $this->denyClients($request)) return $deny;

        $thread = DiscussionThread::findOrFail($id);
        $validated = $request->validate(['message' => 'required|string']);

        $message = DiscussionMessage::create([
            'thread_id' => $thread->id,
            'author_id' => $request->user()->id,
            'content'   => $validated['message'],
        ]);
        $thread->touch();

        return response()->json([
            'id'     => $message->id,
            'author' => $request->user()->name,
            'time'   => 'Just now',
            'text'   => $message->content,
        ], 201);
    }
}
