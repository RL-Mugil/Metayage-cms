<?php
namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class NotificationBroadcast implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly string $type,
        public readonly string $title,
        public readonly string $body,
        public readonly int $userId,
    ) {}

    public function broadcastOn(): array
    {
        return [new Channel("user.{$this->userId}")];
    }

    public function broadcastAs(): string
    {
        return 'notification';
    }
}
