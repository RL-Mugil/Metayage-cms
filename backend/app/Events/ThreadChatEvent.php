<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Real-time signal for a discussion thread room (DM or group thread).
 * Broadcasts synchronously so no queue worker is required. Same `action`
 * discriminator and `.chat` alias as CaseChatEvent so the client ChatRoom
 * handles both identically.
 */
class ThreadChatEvent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /** @param array<string, mixed> $payload */
    public function __construct(
        public readonly int $threadId,
        public readonly string $action,
        public readonly array $payload,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel("chat.thread.{$this->threadId}")];
    }

    public function broadcastAs(): string
    {
        return 'chat';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return ['action' => $this->action, 'data' => $this->payload];
    }
}
