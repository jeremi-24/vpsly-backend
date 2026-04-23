<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class RuntimeLogEvent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $resourceId,
        public string $message,
        public string $channelName = null,
        public string $type = 'info'
    ) {
        $this->channelName = $channelName ?? "application.{$this->resourceId}.runtime-logs";
    }

    public function broadcastOn(): array
    {
        return [
            new Channel($this->channelName),
        ];
    }

    public function broadcastAs(): string
    {
        return 'runtime.log';
    }
}
