<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Real-time signal for a single case chat room. One event type carries an
 * `action` discriminator so the client can switch on it:
 *   message.sent | message.updated | message.deleted | read
 * Typing indicators are handled client-side via Echo whispers (no server round
 * trip), so they are deliberately absent here.
 */
class CaseChatEvent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public readonly int $projectId,
        public readonly string $action,
        public readonly array $payload,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel("chat.project.{$this->projectId}")];
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
