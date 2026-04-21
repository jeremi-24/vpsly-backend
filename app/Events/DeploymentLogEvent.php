<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class DeploymentLogEvent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $deploymentId,
        public string $line
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('deployment.' . $this->deploymentId),
            new Channel('deployment.' . $this->deploymentId), // Utilisation de Channel public pour la simplification MVP Reverb si besoin, à adapter pour auth.
        ];
    }
}
