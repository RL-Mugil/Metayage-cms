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
        if ($request->user()->isClientRole()) {
            return response()->json(['message' => 'Forbidden'], 403);
        }
        return null;
    }

    /** Resolve the client record for a portal user (null for internal staff). */
    private function clientFor(Request $request): ?\App\Models\Client
    {
        $user = $request->user();
        if (! $user->isClientRole()) return null;
        return $request->attributes->get('portal_client') ?? \App\Models\Client::forUser($user);
    }

    public function index(Request $request)
    {
        $user = $request->user();
        $query = DiscussionThread::with(['messages.author:id,name'])->orderByDesc('updated_at');

        if ($user->isClientRole()) {
            // Portal users see only threads shared with their client.
            $client = $this->clientFor($request);
            if (! $client) return response()->json(['message' => 'Forbidden'], 403);
            $query->where('client_id', $client->id);
        } elseif (in_array($user->role, ['associate', 'paralegal'])) {
            // General threads (no project) are visible to all staff.
            // Project threads are visible only if the user is assigned to that project.
            $query->where(function ($q) use ($user) {
                $q->whereNull('project_id')
                  ->orWhereHas('project', function ($pq) use ($user) {
                      $pq->where('assigned_manager_id', $user->id)
                         ->orWhere('assigned_partner_id', $user->id)
                         ->orWhere('patent_engineer_id', $user->id)
                         ->orWhereHas('tasks', fn ($t) => $t->where('assignee_id', $user->id));
                  });
            });
        }
        $paginated = PaginationHelper::paginate($query, $request);

        $clientNames = \App\Models\Client::whereIn('id', collect($paginated['data'])->pluck('client_id')->filter()->unique())
            ->pluck('company_name', 'id');

        $paginated['data'] = $paginated['data']->map(fn ($t) => [
            'id'          => $t->id,
            'title'       => $t->title,
            'tag'         => $t->tag ?? 'General',
            'status'      => $t->status,
            'client_id'   => $t->client_id,
            'client_name' => $t->client_id ? ($clientNames[$t->client_id] ?? null) : null,
            'author'      => $t->messages->first()?->author?->name ?? '—',
            'last_reply'  => $t->updated_at?->diffForHumans(),
            'messages'    => $t->messages->map(fn ($m) => [
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
        $user = $request->user();

        $validated = $request->validate([
            'title'     => 'required|string|max:255',
            'tag'       => 'nullable|in:General,Project,HR,Finance',
            'message'   => 'required|string',
            'client_id' => 'nullable|integer|exists:clients,id',
        ]);

        if ($user->isClientRole()) {
            // Portal users always post into their own client's space.
            $client = $this->clientFor($request);
            if (! $client) return response()->json(['message' => 'Forbidden'], 403);
            $validated['client_id'] = $client->id;
            // Clients cannot use internal HR/Finance tags.
            if (in_array($validated['tag'] ?? 'General', ['HR', 'Finance'])) {
                $validated['tag'] = 'General';
            }
        }

        $thread = DB::transaction(function () use ($validated, $request) {
            $thread = DiscussionThread::create([
                'title'     => $validated['title'],
                'tag'       => $validated['tag'] ?? 'General',
                'client_id' => $validated['client_id'] ?? null,
                'status'    => 'Open',
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
        $user   = $request->user();
        $thread = DiscussionThread::findOrFail($id);

        if ($thread->status === 'Closed') {
            return response()->json(['message' => 'Cannot reply to a closed discussion.'], 422);
        }

        // Portal users may only reply within their own client's threads.
        if ($user->isClientRole()) {
            $client = $this->clientFor($request);
            if (! $client || (int) $thread->client_id !== (int) $client->id) {
                return response()->json(['message' => 'Forbidden'], 403);
            }
        }

        if (in_array($user->role, ['associate', 'paralegal']) && $thread->project_id !== null) {
            $project = $thread->project;
            $canReply = $project && (
                $project->assigned_manager_id === $user->id ||
                $project->assigned_partner_id === $user->id ||
                $project->patent_engineer_id  === $user->id ||
                $project->tasks()->where('assignee_id', $user->id)->exists()
            );
            if (! $canReply) {
                return response()->json(['message' => 'Forbidden'], 403);
            }
        }

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

    public function update(Request $request, $id)
    {
        $user   = $request->user();
        $thread = DiscussionThread::findOrFail($id);

        // Portal users may only touch threads in their own client space.
        if ($user->isClientRole()) {
            $client = $this->clientFor($request);
            if (! $client || (int) $thread->client_id !== (int) $client->id) {
                return response()->json(['message' => 'Forbidden'], 403);
            }
        }

        // Only the creator (first message author) or super_admin/partner can edit
        $firstAuthorId = $thread->messages()->orderBy('id')->value('author_id');
        if ($user->id !== $firstAuthorId && !in_array($user->role, ['super_admin', 'partner'])) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $validated = $request->validate([
            'title'  => 'sometimes|string|max:255',
            'tag'    => 'sometimes|nullable|in:General,Project,HR,Finance',
            'status' => 'sometimes|in:Open,Closed',
        ]);

        $thread->update($validated);

        return response()->json(['ok' => true]);
    }

    public function destroy(Request $request, $id)
    {
        $user   = $request->user();
        $thread = DiscussionThread::findOrFail($id);

        if ($user->isClientRole()) {
            $client = $this->clientFor($request);
            if (! $client || (int) $thread->client_id !== (int) $client->id) {
                return response()->json(['message' => 'Forbidden'], 403);
            }
        }

        $firstAuthorId = $thread->messages()->orderBy('id')->value('author_id');
        if ($user->id !== $firstAuthorId && !in_array($user->role, ['super_admin', 'partner'])) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        DB::transaction(function () use ($thread) {
            $thread->messages()->delete();
            $thread->delete();
        });

        return response()->json(['ok' => true]);
    }
}
