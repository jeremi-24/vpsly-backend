<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class DeploymentStatusUpdatedEvent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $deploymentId,
        public int $applicationId,
        public string $status,
        public bool $isDeploying
    ) {}

    public function broadcastOn(): array
    {
        return [
            new Channel('deployment.' . $this->deploymentId),
            new Channel('application.' . $this->applicationId),
        ];
    }
}
