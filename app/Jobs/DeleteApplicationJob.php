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
        public string $infrastructureType,
        public string $appSlug,
        public ?string $targetPath = null
    ) {}

    public function handle(DockerService $docker, \App\Services\Deployment\SSHService $ssh): void
    {
        $server = Server::find($this->serverId);
        if (!$server) return;

        if ($this->infrastructureType === 'legacy' && !empty($this->targetPath)) {
            // Suppression du dossier pour Legacy
            $ssh->connect($server);
            $ssh->exec("rm -rf " . escapeshellarg($this->targetPath));
            $ssh->disconnect();
        } else {
            // Suppression Docker pour Clean
            $docker->stopAndRemove($server, $this->appSlug);
        }
    }
}
