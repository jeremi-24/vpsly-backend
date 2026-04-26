<?php

namespace App\Jobs;

use App\Models\Server;
use App\Services\Deployment\SSHService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class DeleteDatabaseJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;
    public $timeout = 300;

    public function __construct(
        public int $serverId,
        public string $databaseUuid
    ) {}

    public function handle(SSHService $ssh): void
    {
        $server = Server::find($this->serverId);
        if (!$server) return;

        $dbPath = "/var/www/vpsly/databases/{$this->databaseUuid}";
        
        try {
            $ssh->connect($server);

            // 1. Arrêt du container via Docker Compose s'il existe
            $ssh->exec("[ -d {$dbPath} ] && cd {$dbPath} && docker compose down || true");

            // 2. Sécurité : On force la suppression du container par son UUID au cas où
            $ssh->exec("docker rm -f {$this->databaseUuid} || true");

            // 3. Suppression du dossier de configuration
            $ssh->exec("rm -rf {$dbPath}");

            Log::info("[DeleteDatabaseJob] Successfully cleaned up database {$this->databaseUuid} from server {$server->ip}");

        } catch (\Exception $e) {
            Log::error("[DeleteDatabaseJob] Cleanup failed for {$this->databaseUuid}: " . $e->getMessage());
            throw $e;
        } finally {
            $ssh->disconnect();
        }
    }
}
