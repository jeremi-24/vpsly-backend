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
        public int $applicationId,
        public string $message,
        public string $type = 'info'
    ) {}

    public function broadcastOn(): array
    {
        // Canal public car les permissions applicatives sont gérées en amont 
        // ou canal privé si on veut renforcer la sécurité (nécessite auth front).
        return [
            new Channel("application.{$this->applicationId}.runtime-logs"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'runtime.log';
    }
}
