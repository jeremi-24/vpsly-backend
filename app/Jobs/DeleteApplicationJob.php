<?php

namespace App\Jobs;

use App\Models\Server;
use App\Services\Deployment\DockerService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class DeleteApplicationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;
    public $timeout = 300;

    public function __construct(
        public int $serverId,
        public string $appSlug
    ) {}

    public function handle(DockerService $docker): void
    {
        $server = Server::find($this->serverId);
        if (!$server) return;

        $docker->stopAndRemove($server, $this->appSlug);
    }
}
