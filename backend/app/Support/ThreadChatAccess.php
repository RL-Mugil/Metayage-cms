<?php

namespace App\Support;

use App\Models\Client;
use App\Models\DiscussionThread;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Access + participant resolution for discussion-thread chat rooms used by the
 * global Discussions page. Handles two kinds:
 *   - dm      : private direct message — access = explicit participant.
 *   - thread  : group discussion — mirrors the DiscussionController visibility
 *               rules (client-scoped for portal users, assignment-scoped for
 *               associates, otherwise internal staff).
 *
 * (Per-case chat, kind = case_chat, is handled separately by CaseChatAccess /
 * ProjectChatController and its own chat.project.{id} channel.)
 */
class ThreadChatAccess
{
    public static function canAccess(User $user, DiscussionThread $thread): bool
    {
        if ($thread->kind === 'dm') {
            return $thread->participants()->where('users.id', $user->id)->exists();
        }

        // Group thread visibility.
        if ($user->isClientRole()) {
            $client = Client::forUser($user);
            return $client !== null && (int) $thread->client_id === (int) $client->id;
        }

        if ($user->role === 'associate' && $thread->project_id !== null) {
            $project = $thread->project;
            return $project !== null && (
                $project->patent_engineer_id  === $user->id ||
                $project->assigned_manager_id === $user->id ||
                $project->secondary_manager_id === $user->id ||
                $project->tasks()->where('assignee_id', $user->id)->exists()
            );
        }

        // Other internal staff: general threads are visible.
        return true;
    }

    /**
     * Named participants for the roster, @mention autocomplete and read
     * receipts. DMs list their members; group threads list everyone who has
     * spoken (so mentions resolve without enumerating the whole firm).
     *
     * @return Collection<int, User>
     */
    public static function participants(DiscussionThread $thread): Collection
    {
        if ($thread->kind === 'dm') {
            return $thread->participants()->get(['users.id', 'name', 'role', 'avatar_url']);
        }

        $authorIds = $thread->messages()->distinct()->pluck('author_id')->filter();

        return User::whereIn('id', $authorIds)->get(['id', 'name', 'role', 'avatar_url']);
    }
}
