<?php

namespace App\Events;

use App\Models\Backup;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class BackupUpdatedEvent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $backup;

    public function __construct(Backup $backup)
    {
        $this->backup = $backup;
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('application.' . $this->backup->application_id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'BackupUpdatedEvent';
    }
}
