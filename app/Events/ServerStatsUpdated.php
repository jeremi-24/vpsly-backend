<?php

namespace App\Events;

use App\Models\Server;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ServerStatsUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public Server $server,
        public array $stats
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("server.{$this->server->id}"),
            new PrivateChannel("user.{$this->server->user_id}"), // Pour le dashboard global
        ];
    }

    public function broadcastAs(): string
    {
        return 'ServerStatsUpdated';
    }
}
