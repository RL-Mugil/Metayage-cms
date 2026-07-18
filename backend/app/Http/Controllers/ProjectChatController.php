<?php

namespace App\Http\Controllers;

use App\Events\CaseChatEvent;
use App\Models\DiscussionMessage;
use App\Models\DiscussionMessageRead;
use App\Models\DiscussionThread;
use App\Models\Project;
use App\Models\User;
use App\Support\CaseChatAccess;
use App\Support\Notifier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Real-time, per-case Google-Chat-style room. Every action is gated by
 * CaseChatAccess (same rule as the private broadcast channel) and pushes a
 * CaseChatEvent so other participants update instantly.
 */
class ProjectChatController extends Controller
{
    private function authorizeCase(Request $request, Project $project): void
    {
        if (! CaseChatAccess::canAccess($request->user(), $project)) {
            abort(403, 'This case chat is restricted to people assigned to the matter.');
        }
    }

    /** Get-or-create the single canonical chat thread for a project. */
    private function threadFor(Project $project): DiscussionThread
    {
        return DiscussionThread::firstOrCreate(
            ['project_id' => $project->id, 'kind' => 'case_chat'],
            [
                'client_id' => $project->client_id,
                'title'     => 'Case Discussion',
                'tag'       => 'Project',
                'status'    => 'Open',
            ],
        );
    }

    /** Shape a message for the API/broadcast payload. */
    private function present(DiscussionMessage $message): array
    {
        return [
            'id'          => $message->id,
            'thread_id'   => $message->thread_id,
            'author_id'   => $message->author_id,
            'author'      => $message->author?->name ?? 'Unknown',
            'avatar_url'  => $message->author?->avatar_url,
            'role'        => $message->author?->role,
            'content'     => $message->content,
            'attachments' => $message->attachments ?? [],
            'mentions'    => $message->mentions ?? [],
            'edited_at'   => $message->edited_at?->toISOString(),
            'created_at'  => $message->created_at?->toISOString(),
        ];
    }

    public function show(Request $request, int $projectId)
    {
        $project = Project::with('client:id,portal_user_id')->findOrFail($projectId);
        $this->authorizeCase($request, $project);

        $thread = $this->threadFor($project);

        $messages = $thread->messages()
            ->with('author:id,name,role,avatar_url')
            ->orderBy('id')
            ->get();

        $participants = CaseChatAccess::participants($project)
            ->map(fn (User $u) => [
                'id'         => $u->id,
                'name'       => $u->name,
                'role'       => $u->role,
                'avatar_url' => $u->avatar_url,
            ])->values();

        // Read state of every participant, for read-receipt avatars.
        $reads = $thread->reads()->get(['user_id', 'last_read_message_id'])
            ->mapWithKeys(fn ($r) => [(int) $r->user_id => (int) $r->last_read_message_id]);

        return response()->json([
            'thread_id'    => $thread->id,
            'project_id'   => $project->id,
            'channel'      => "chat.project.{$project->id}",
            'current_user' => ['id' => $request->user()->id, 'name' => $request->user()->name],
            'can_moderate' => in_array($request->user()->role, ['super_admin', 'partner'], true),
            'participants' => $participants,
            'messages'     => $messages->map(fn ($m) => $this->present($m))->values(),
            'reads'        => $reads,
        ]);
    }

    public function send(Request $request, int $projectId)
    {
        $project = Project::with('client:id,portal_user_id')->findOrFail($projectId);
        $this->authorizeCase($request, $project);

        $validated = $request->validate([
            'content'      => 'required_without:attachments|nullable|string|max:8000',
            'attachments'  => 'nullable|array|max:10',
            'attachments.*' => 'file|max:51200|mimes:pdf,doc,docx,xls,xlsx,ppt,pptx,txt,csv,png,jpg,jpeg,gif,zip',
            'mentions'     => 'nullable|array',
            'mentions.*'   => 'integer',
        ]);

        $thread = $this->threadFor($project);
        $user   = $request->user();

        $stored = [];
        foreach ($request->file('attachments', []) as $file) {
            $original = preg_replace('/[^\w.\- ()]/', '_', $file->getClientOriginalName());
            $path = $file->storeAs("documents/chat/{$thread->id}", uniqid() . '_' . $original, 'local');
            $stored[] = [
                'name' => $original,
                'path' => $path,
                'size' => $file->getSize(),
                'type' => $file->getClientMimeType(),
            ];
        }

        // Keep only mentions that are genuine participants of this case.
        $participantIds = CaseChatAccess::participants($project)->pluck('id')->all();
        $mentions = collect($validated['mentions'] ?? [])
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => in_array($id, $participantIds, true))
            ->unique()->values()->all();

        $message = DiscussionMessage::create([
            'thread_id'   => $thread->id,
            'author_id'   => $user->id,
            'content'     => $validated['content'] ?? '',
            'attachments' => $stored ?: null,
            'mentions'    => $mentions ?: null,
        ]);
        $thread->touch();
        $message->setRelation('author', $user);

        // Author has implicitly read their own message.
        $this->storeRead($thread->id, $user->id, $message->id);

        $payload = $this->present($message);
        broadcast(new CaseChatEvent($project->id, 'message.sent', $payload))->toOthers();

        // @mention notifications (never break the request if they fail).
        if ($mentions) {
            Notifier::push(
                $mentions,
                'chat_mention',
                "{$user->name} mentioned you",
                \Illuminate\Support\Str::limit(strip_tags($message->content), 120) ?: 'Shared an attachment',
                "/projects/{$project->id}",
                ['project_id' => $project->id, 'message_id' => $message->id],
            );
        }

        return response()->json($payload, 201);
    }

    public function update(Request $request, int $projectId, int $messageId)
    {
        $project = Project::with('client:id,portal_user_id')->findOrFail($projectId);
        $this->authorizeCase($request, $project);

        $message = DiscussionMessage::where('thread_id', $this->threadFor($project)->id)
            ->findOrFail($messageId);

        $user = $request->user();
        if ($message->author_id !== $user->id && ! in_array($user->role, ['super_admin', 'partner'], true)) {
            abort(403, 'You can only edit your own messages.');
        }

        $validated = $request->validate(['content' => 'required|string|max:8000']);
        $message->update(['content' => $validated['content'], 'edited_at' => now()]);
        $message->load('author:id,name,role,avatar_url');

        $payload = $this->present($message);
        broadcast(new CaseChatEvent($project->id, 'message.updated', $payload))->toOthers();

        return response()->json($payload);
    }

    public function destroy(Request $request, int $projectId, int $messageId)
    {
        $project = Project::with('client:id,portal_user_id')->findOrFail($projectId);
        $this->authorizeCase($request, $project);

        $message = DiscussionMessage::where('thread_id', $this->threadFor($project)->id)
            ->findOrFail($messageId);

        $user = $request->user();
        if ($message->author_id !== $user->id && ! in_array($user->role, ['super_admin', 'partner'], true)) {
            abort(403, 'You can only delete your own messages.');
        }

        $message->delete(); // soft delete
        broadcast(new CaseChatEvent($project->id, 'message.deleted', ['id' => $message->id]))->toOthers();

        return response()->json(['ok' => true]);
    }

    /** Mark the thread read up to the newest message for the current user. */
    public function markRead(Request $request, int $projectId)
    {
        $project = Project::with('client:id,portal_user_id')->findOrFail($projectId);
        $this->authorizeCase($request, $project);

        $thread = $this->threadFor($project);
        $lastId = (int) $request->input('last_read_message_id', 0);
        if ($lastId <= 0) {
            $lastId = (int) $thread->messages()->max('id');
        }

        $this->storeRead($thread->id, $request->user()->id, $lastId);

        broadcast(new CaseChatEvent($project->id, 'read', [
            'user_id'              => $request->user()->id,
            'last_read_message_id' => $lastId,
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

    public function downloadAttachment(Request $request, int $projectId)
    {
        $project = Project::with('client:id,portal_user_id')->findOrFail($projectId);
        $this->authorizeCase($request, $project);

        $request->validate(['path' => 'required|string']);
        $path = $request->input('path');

        $thread = $this->threadFor($project);
        $prefix = "documents/chat/{$thread->id}/";
        if (str_contains($path, '..') || ! str_starts_with($path, $prefix)) {
            return response()->json(['message' => 'Invalid path'], 422);
        }
        if (! Storage::disk('local')->exists($path)) {
            return response()->json(['message' => 'File not found'], 404);
        }

        // Strip the uniqid() prefix from the download filename.
        $base = preg_replace('/^[a-f0-9]+_/', '', basename($path));

        return Storage::disk('local')->download($path, $base);
    }
}
