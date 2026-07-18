<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Lightweight "you have a new chat message" nudge, delivered on the recipient's
 * public user.{id} channel. Drives the global unread badge without a toast for
 * every message (mentions/DMs still raise a full notification separately).
 */
class ChatUnreadBroadcast implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /** @param array<string, mixed> $payload */
    public function __construct(
        public readonly int $userId,
        public readonly array $payload,
    ) {}

    public function broadcastOn(): array
    {
        return [new Channel("user.{$this->userId}")];
    }

    public function broadcastAs(): string
    {
        return 'chat.unread';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return $this->payload;
    }
}
