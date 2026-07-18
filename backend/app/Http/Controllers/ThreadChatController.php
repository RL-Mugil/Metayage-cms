<?php

namespace App\Http\Controllers;

use App\Events\ChatUnreadBroadcast;
use App\Events\ThreadChatEvent;
use App\Models\DiscussionMessage;
use App\Models\DiscussionMessageRead;
use App\Models\DiscussionThread;
use App\Models\User;
use App\Support\Notifier;
use App\Support\ThreadChatAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Chat for global discussion threads and direct messages. Gives the same
 * real-time experience as the per-case chat (broadcast, typing, mentions,
 * read receipts) on a chat.thread.{id} private channel.
 */
class ThreadChatController extends Controller
{
    private const PAGE_SIZE = 50;

    private function threadOrFail(int $threadId): DiscussionThread
    {
        return DiscussionThread::findOrFail($threadId);
    }

    private function guardThread(Request $request, DiscussionThread $thread): void
    {
        if (! ThreadChatAccess::canAccess($request->user(), $thread)) {
            abort(403, 'You do not have access to this conversation.');
        }
    }

    private function present(DiscussionMessage $m): array
    {
        return [
            'id'          => $m->id,
            'thread_id'   => $m->thread_id,
            'author_id'   => $m->author_id,
            'author'      => $m->author?->name ?? 'Unknown',
            'avatar_url'  => $m->author?->avatar_url,
            'role'        => $m->author?->role,
            'content'     => $m->content,
            'attachments' => $m->attachments ?? [],
            'mentions'    => $m->mentions ?? [],
            'edited_at'   => $m->edited_at?->toISOString(),
            'created_at'  => $m->created_at?->toISOString(),
        ];
    }

    private function state(Request $request, DiscussionThread $thread): array
    {
        $latest = $thread->messages()->with('author:id,name,role,avatar_url')
            ->orderByDesc('id')->limit(self::PAGE_SIZE + 1)->get();
        $hasMore = $latest->count() > self::PAGE_SIZE;
        $messages = $latest->take(self::PAGE_SIZE)->sortBy('id')->values();

        $participants = ThreadChatAccess::participants($thread)
            ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name, 'role' => $u->role, 'avatar_url' => $u->avatar_url])
            ->values();
        $reads = $thread->reads()->get(['user_id', 'last_read_message_id'])
            ->mapWithKeys(fn ($r) => [(int) $r->user_id => (int) $r->last_read_message_id]);

        return [
            'thread_id'    => $thread->id,
            'channel'      => "chat.thread.{$thread->id}",
            'current_user' => ['id' => $request->user()->id, 'name' => $request->user()->name],
            'can_moderate' => in_array($request->user()->role, ['super_admin', 'partner'], true),
            'participants' => $participants,
            'messages'     => $messages->map(fn ($m) => $this->present($m))->values(),
            'has_more'     => $hasMore,
            'reads'        => $reads,
        ];
    }

    /** Older messages before a cursor id, for scroll-up pagination. */
    public function history(Request $request, int $threadId)
    {
        $thread = $this->threadOrFail($threadId);
        $this->guardThread($request, $thread);

        $before = (int) $request->query('before', 0);
        $query = $thread->messages()->with('author:id,name,role,avatar_url')->orderByDesc('id');
        if ($before > 0) {
            $query->where('id', '<', $before);
        }
        $page = $query->limit(self::PAGE_SIZE + 1)->get();

        return response()->json([
            'messages' => $page->take(self::PAGE_SIZE)->sortBy('id')->values()->map(fn ($m) => $this->present($m)),
            'has_more' => $page->count() > self::PAGE_SIZE,
        ]);
    }

    /** Total unread across the user's DMs and the case chats they're assigned to. */
    public function unreadCount(Request $request)
    {
        $me = $request->user();

        // DM + group threads the user participates in.
        $threadIds = DiscussionThread::whereHas('participants', fn ($q) => $q->where('users.id', $me->id))
            ->pluck('id');

        // Case chats for projects the user is assigned to (bounded, not every project).
        if (! $me->isClientRole()) {
            $projectIds = \App\Models\Project::query()
                ->where('assigned_partner_id', $me->id)
                ->orWhere('assigned_manager_id', $me->id)
                ->orWhere('secondary_manager_id', $me->id)
                ->orWhere('patent_engineer_id', $me->id)
                ->pluck('id');
            $caseThreadIds = DiscussionThread::where('kind', 'case_chat')
                ->whereIn('project_id', $projectIds)->pluck('id');
            $threadIds = $threadIds->merge($caseThreadIds)->unique();
        }

        $count = 0;
        foreach ($threadIds as $tid) {
            $lastRead = (int) DiscussionMessageRead::where('thread_id', $tid)->where('user_id', $me->id)->value('last_read_message_id');
            $count += DiscussionMessage::where('thread_id', $tid)
                ->where('id', '>', $lastRead)->where('author_id', '!=', $me->id)->count();
        }

        return response()->json(['count' => $count]);
    }

    /* ── DM list + open ── */

    public function dmIndex(Request $request)
    {
        $me = $request->user();
        $threads = DiscussionThread::where('kind', 'dm')
            ->whereHas('participants', fn ($q) => $q->where('users.id', $me->id))
            ->with(['participants:id,name,role,avatar_url'])
            ->get();

        $data = $threads->map(function (DiscussionThread $t) use ($me) {
            $other = $t->participants->firstWhere('id', '!=', $me->id) ?? $t->participants->first();
            $last  = $t->messages()->latest('id')->first();
            $lastRead = (int) DiscussionMessageRead::where('thread_id', $t->id)->where('user_id', $me->id)->value('last_read_message_id');
            $unread = $t->messages()->where('id', '>', $lastRead)->where('author_id', '!=', $me->id)->count();
            return [
                'thread_id'  => $t->id,
                'user'       => $other ? ['id' => $other->id, 'name' => $other->name, 'role' => $other->role, 'avatar_url' => $other->avatar_url] : null,
                'last'       => $last ? Str::limit(strip_tags($last->content) ?: 'Attachment', 60) : null,
                'updated_at' => $t->updated_at?->toISOString(),
                'unread'     => $unread,
            ];
        })->sortByDesc('updated_at')->values();

        return response()->json(['data' => $data]);
    }

    /** People the current user can start a DM with (internal staff, excl. self). */
    public function contacts(Request $request)
    {
        $me = $request->user();
        if ($me->isClientRole()) {
            return response()->json(['data' => []]);
        }
        $users = User::whereIn('role', User::STAFF_ROLES)
            ->where('id', '!=', $me->id)
            ->whereRaw('LOWER(status) = ?', ['active'])
            ->orderBy('name')
            ->get(['id', 'name', 'role', 'avatar_url']);

        return response()->json(['data' => $users]);
    }

    public function openDm(Request $request, int $userId)
    {
        $me = $request->user();
        if ($me->isClientRole()) abort(403);
        if ($userId === $me->id) abort(422, 'You cannot message yourself.');
        $other = User::findOrFail($userId);

        $threadId = DB::table('discussion_participants as a')
            ->join('discussion_participants as b', 'a.thread_id', '=', 'b.thread_id')
            ->join('discussion_threads as t', 't.id', '=', 'a.thread_id')
            ->where('t.kind', 'dm')
            ->where('a.user_id', $me->id)
            ->where('b.user_id', $other->id)
            ->value('a.thread_id');

        if (! $threadId) {
            $thread = DB::transaction(function () use ($me, $other) {
                $thread = DiscussionThread::create([
                    'title'  => 'Direct message',
                    'tag'    => 'General',
                    'kind'   => 'dm',
                    'status' => 'Open',
                ]);
                $thread->participants()->syncWithoutDetaching([$me->id, $other->id]);
                return $thread;
            });
            $threadId = $thread->id;
        }

        $thread = DiscussionThread::findOrFail($threadId);
        $this->guardThread($request, $thread);

        return response()->json($this->state($request, $thread));
    }

    /* ── Room operations ── */

    public function show(Request $request, int $threadId)
    {
        $thread = $this->threadOrFail($threadId);
        $this->guardThread($request, $thread);
        return response()->json($this->state($request, $thread));
    }

    public function send(Request $request, int $threadId)
    {
        $thread = $this->threadOrFail($threadId);
        $this->guardThread($request, $thread);
        if ($thread->status === 'Closed') abort(422, 'This conversation is closed.');

        $validated = $request->validate([
            'content'       => 'required_without:attachments|nullable|string|max:8000',
            'attachments'   => 'nullable|array|max:10',
            'attachments.*' => 'file|max:51200|mimes:pdf,doc,docx,xls,xlsx,ppt,pptx,txt,csv,png,jpg,jpeg,gif,zip',
            'mentions'      => 'nullable|array',
            'mentions.*'    => 'integer',
        ]);
        $user = $request->user();

        $stored = [];
        foreach ($request->file('attachments', []) as $file) {
            $original = preg_replace('/[^\w.\- ()]/', '_', $file->getClientOriginalName());
            $path = $file->storeAs("documents/chat/{$thread->id}", uniqid() . '_' . $original, 'local');
            $stored[] = ['name' => $original, 'path' => $path, 'size' => $file->getSize(), 'type' => $file->getClientMimeType()];
        }

        $participantIds = ThreadChatAccess::participants($thread)->pluck('id')->all();
        $mentions = collect($validated['mentions'] ?? [])->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => in_array($id, $participantIds, true))->unique()->values()->all();

        $message = DiscussionMessage::create([
            'thread_id'   => $thread->id,
            'author_id'   => $user->id,
            'content'     => $validated['content'] ?? '',
            'attachments' => $stored ?: null,
            'mentions'    => $mentions ?: null,
        ]);
        $thread->touch();
        $message->setRelation('author', $user);
        $this->storeRead($thread->id, $user->id, $message->id);

        $payload = $this->present($message);
        broadcast(new ThreadChatEvent($thread->id, 'message.sent', $payload))->toOthers();

        // Nudge the global unread badge for every other participant.
        foreach (array_diff($participantIds, [$user->id]) as $recipientId) {
            broadcast(new ChatUnreadBroadcast((int) $recipientId, [
                'thread_id' => $thread->id, 'kind' => $thread->kind, 'from' => $user->name,
            ]));
        }

        // Notify: explicit mentions, plus the DM counterpart on every message.
        $notify = $mentions;
        if ($thread->kind === 'dm') {
            $notify = array_values(array_unique(array_merge($notify, array_diff($participantIds, [$user->id]))));
        }
        if ($notify) {
            Notifier::push(
                $notify,
                $thread->kind === 'dm' ? 'direct_message' : 'chat_mention',
                $thread->kind === 'dm' ? "{$user->name} messaged you" : "{$user->name} mentioned you",
                Str::limit(strip_tags($message->content), 120) ?: 'Shared an attachment',
                '/discussions',
                ['thread_id' => $thread->id, 'message_id' => $message->id],
            );
        }

        return response()->json($payload, 201);
    }

    public function update(Request $request, int $threadId, int $messageId)
    {
        $thread = $this->threadOrFail($threadId);
        $this->guardThread($request, $thread);
        $message = DiscussionMessage::where('thread_id', $thread->id)->findOrFail($messageId);
        $user = $request->user();
        if ($message->author_id !== $user->id && ! in_array($user->role, ['super_admin', 'partner'], true)) {
            abort(403, 'You can only edit your own messages.');
        }
        $validated = $request->validate(['content' => 'required|string|max:8000']);
        $message->update(['content' => $validated['content'], 'edited_at' => now()]);
        $message->load('author:id,name,role,avatar_url');

        $payload = $this->present($message);
        broadcast(new ThreadChatEvent($thread->id, 'message.updated', $payload))->toOthers();
        return response()->json($payload);
    }

    public function destroy(Request $request, int $threadId, int $messageId)
    {
        $thread = $this->threadOrFail($threadId);
        $this->guardThread($request, $thread);
        $message = DiscussionMessage::where('thread_id', $thread->id)->findOrFail($messageId);
        $user = $request->user();
        if ($message->author_id !== $user->id && ! in_array($user->role, ['super_admin', 'partner'], true)) {
            abort(403, 'You can only delete your own messages.');
        }
        $message->delete();
        broadcast(new ThreadChatEvent($thread->id, 'message.deleted', ['id' => $message->id]))->toOthers();
        return response()->json(['ok' => true]);
    }

    public function markRead(Request $request, int $threadId)
    {
        $thread = $this->threadOrFail($threadId);
        $this->guardThread($request, $thread);
        $lastId = (int) $request->input('last_read_message_id', 0);
        if ($lastId <= 0) $lastId = (int) $thread->messages()->max('id');
        $this->storeRead($thread->id, $request->user()->id, $lastId);
        broadcast(new ThreadChatEvent($thread->id, 'read', [
            'user_id' => $request->user()->id, 'last_read_message_id' => $lastId,
        ]))->toOthers();
        return response()->json(['ok' => true, 'last_read_message_id' => $lastId]);
    }

    private function storeRead(int $threadId, int $userId, int $lastMessageId): void
    {
        $existing = DiscussionMessageRead::firstOrNew(['thread_id' => $threadId, 'user_id' => $userId]);
        if ($lastMessageId >= (int) $existing->last_read_message_id) {
            $existing->last_read_message_id = $lastMessageId;
            $existing->read_at = now();
            $existing->save();
        }
    }

    public function downloadAttachment(Request $request, int $threadId)
    {
        $thread = $this->threadOrFail($threadId);
        $this->guardThread($request, $thread);
        $request->validate(['path' => 'required|string']);
        $path = $request->input('path');
        $prefix = "documents/chat/{$thread->id}/";
        if (str_contains($path, '..') || ! str_starts_with($path, $prefix)) {
            return response()->json(['message' => 'Invalid path'], 422);
        }
        if (! Storage::disk('local')->exists($path)) {
            return response()->json(['message' => 'File not found'], 404);
        }
        $base = preg_replace('/^[a-f0-9]+_/', '', basename($path));
        return Storage::disk('local')->download($path, $base);
    }
}
