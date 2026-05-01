<?php

namespace App\Events;

use App\Models\StandaloneDatabase;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class DatabaseStatusUpdatedEvent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public StandaloneDatabase $database
    ) {}

    public function broadcastOn(): array
    {
        return [
            new Channel('database.' . $this->database->id),
        ];
    }
}
