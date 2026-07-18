<?php

use App\Models\DiscussionThread;
use App\Models\Project;
use App\Models\User;
use App\Support\CaseChatAccess;
use App\Support\ThreadChatAccess;
use Illuminate\Support\Facades\Broadcast;

/**
 * Per-user notification channel (already used by NotificationBroadcast, which
 * broadcasts on a public channel; this authorizes the private form too).
 */
Broadcast::channel('user.{userId}', function (User $user, int $userId) {
    return (int) $user->id === (int) $userId;
});

/**
 * Private case chat room. Authorization mirrors CaseChatAccess exactly so a
 * user can only subscribe to a room they are entitled to read.
 */
Broadcast::channel('chat.project.{projectId}', function (User $user, int $projectId) {
    $project = Project::with('client:id,portal_user_id')->find($projectId);
    if (! $project || ! CaseChatAccess::canAccess($user, $project)) {
        return false;
    }

    // The returned payload becomes the "member" info for presence use; keep it
    // lean and non-sensitive.
    return ['id' => $user->id, 'name' => $user->name];
});

/**
 * Discussion-thread rooms — direct messages and group threads on the global
 * Discussions page. Authorized via ThreadChatAccess (participant for DMs,
 * visibility rules for group threads).
 */
Broadcast::channel('chat.thread.{threadId}', function (User $user, int $threadId) {
    $thread = DiscussionThread::find($threadId);
    if (! $thread || ! ThreadChatAccess::canAccess($user, $thread)) {
        return false;
    }

    return ['id' => $user->id, 'name' => $user->name];
});
