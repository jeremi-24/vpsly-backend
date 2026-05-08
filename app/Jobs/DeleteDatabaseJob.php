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
        public string $databaseUuid,
        public ?string $infrastructureType = 'clean',
        public ?string $type = null,
        public ?string $dbName = null,
        public ?string $dbUser = null
    ) {}

    public function handle(SSHService $ssh): void
    {
        $server = Server::find($this->serverId);
        if (!$server) return;

        $dbPath = "/var/www/vpsly/databases/{$this->databaseUuid}";
        
        try {
            $ssh->connect($server);

            if ($this->infrastructureType === 'legacy') {
                $this->cleanupLegacy($ssh);
            } else {
                $this->cleanupDocker($ssh, $dbPath);
            }

            Log::info("[DeleteDatabaseJob] Successfully cleaned up database {$this->databaseUuid} from server {$server->ip}");

        } catch (\Exception $e) {
            Log::error("[DeleteDatabaseJob] Cleanup failed for {$this->databaseUuid}: " . $e->getMessage());
            throw $e;
        } finally {
            $ssh->disconnect();
        }
    }

    protected function cleanupLegacy(SSHService $ssh): void
    {
        if (!$this->dbName || !$this->dbUser) return;

        if ($this->type === 'mysql' || $this->type === 'mariadb') {
            $dbName = str_replace('`', '``', $this->dbName);
            
            $sql = "DROP DATABASE IF EXISTS `{$dbName}`;\n";
            $sql .= "DROP USER IF EXISTS '{$this->dbUser}'@'localhost';\n";

            $tmpFile = '/tmp/cleanup_mysql_' . bin2hex(random_bytes(8)) . '.sql';
            $ssh->upload($tmpFile, $sql);

            try {
                $ssh->exec("sudo mysql < {$tmpFile}");
            } finally {
                $ssh->exec("rm -f {$tmpFile}");
            }
        } elseif ($this->type === 'postgres') {
            $dbName = str_replace('"', '""', $this->dbName);
            $dbUser = str_replace('"', '""', $this->dbUser);

            $sql = "DROP DATABASE IF EXISTS \"{$dbName}\";\n";
            $sql .= "DROP USER IF EXISTS \"{$dbUser}\";\n";

            $tmpFile = '/tmp/cleanup_pg_' . bin2hex(random_bytes(8)) . '.sql';
            $ssh->upload($tmpFile, $sql);

            try {
                $ssh->exec("sudo -u postgres psql -f {$tmpFile}");
            } finally {
                $ssh->exec("rm -f {$tmpFile}");
            }
        }
    }

    protected function cleanupDocker(SSHService $ssh, string $dbPath): void
    {
        // 1. Arrêt du container via Docker Compose s'il existe
        $ssh->exec("[ -d {$dbPath} ] && cd {$dbPath} && docker compose down || true");

        // 2. Sécurité : On force la suppression du container par son UUID au cas où
        $ssh->exec("docker rm -f {$this->databaseUuid} || true");

        // 3. Suppression du dossier de configuration
        $ssh->exec("rm -rf {$dbPath}");
    }
}
