<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ServerMetricsEvent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $serverId,
        public array $stats
    ) {}

    public function broadcastOn(): array
    {
        return [
            new Channel("server.{$this->serverId}.metrics"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'metrics.updated';
    }
}
