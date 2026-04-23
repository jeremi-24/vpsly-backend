<?php

namespace App\Services\Deployment;

use App\Models\StandalonePostgresql;
use App\Models\Server;
use Exception;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class DatabaseProvisioner
{
    public function __construct(
        protected SSHService $ssh
    ) {}

    /**
     * Déploie ou met à jour une instance de base de données (Style Coolify).
     */
    public function provision(StandalonePostgresql $database): void
    {
        $server = $database->server;
        $containerName = $database->uuid;
        $configDir = "/var/www/vpsly/databases/{$containerName}";

        Log::info("[Database] Provisioning {$database->name} ({$database->image}) on Server: {$server->ip}");

        try {
            $this->ssh->connect($server);

            // 1. Préparation des répertoires
            $this->ssh->exec("mkdir -p \"{$configDir}/docker-entrypoint-initdb.d/\"");

            // 2. Génération du Docker Compose
            $compose = $this->generateCompose($database);
            $composeBase64 = base64_encode($compose);

            // 3. Upload via Base64
            $this->ssh->exec("echo '{$composeBase64}' | base64 -d > \"{$configDir}/docker-compose.yml\"");

            // 4. Déploiement
            $this->ssh->exec("cd \"{$configDir}\" && docker compose pull");
            $this->ssh->exec("docker stop -t 10 \"{$containerName}\" 2>/dev/null || true");
            $this->ssh->exec("docker rm -f \"{$containerName}\" 2>/dev/null || true");
            
            $this->ssh->exec("docker network create vpsly_network 2>/dev/null || true");

            $this->ssh->exec("cd \"{$configDir}\" && docker compose up -d");

            $database->update([
                'status' => 'running',
                'started_at' => now()
            ]);

            Log::info("[Database] Successfully provisioned {$database->name}");

        } catch (Exception $e) {
            Log::error("[Database] Provisioning failed: " . $e->getMessage());
            $database->update(['status' => 'failed']);
            throw $e;
        } finally {
            $this->ssh->disconnect();
        }
    }

    /**
     * Génère le YAML Docker Compose de manière générique.
     */
    protected function generateCompose(StandalonePostgresql $database): string
    {
        $volumeName = "db-data-{$database->uuid}";
        $image = (string) $database->image;
        
        $isPostgres = str_contains($image, 'postgres');
        $isMysql = str_contains($image, 'mysql') || str_contains($image, 'mariadb');
        $isRedis = str_contains($image, 'redis');
        
        $env = [];
        $internalPort = 5432;
        $mountPath = '/var/lib/postgresql/data';

        if ($isPostgres) {
            $env = [
                "POSTGRES_USER={$database->postgres_user}",
                "POSTGRES_PASSWORD={$database->postgres_password}",
                "POSTGRES_DB={$database->postgres_db}",
            ];
            $internalPort = 5432;
            $mountPath = '/var/lib/postgresql/data';
        } elseif ($isMysql) {
            $env = [
                "MYSQL_ROOT_PASSWORD={$database->postgres_password}",
                "MYSQL_DATABASE={$database->postgres_db}",
            ];

            // MYSQL_USER ne peut pas être 'root' dans l'image officielle MySQL
            if ($database->postgres_user !== 'root') {
                $env[] = "MYSQL_USER={$database->postgres_user}";
                $env[] = "MYSQL_PASSWORD={$database->postgres_password}";
            }

            $internalPort = 3306;
            $mountPath = '/var/lib/mysql';
        } elseif ($isRedis) {
            $env = [
                "REDIS_PASSWORD={$database->postgres_password}",
            ];
            $internalPort = 6379;
            $mountPath = '/data';
        }

        $yml = "services:\n";
        $yml .= "  {$database->uuid}:\n";
        $yml .= "    image: \"{$database->image}\"\n";
        $yml .= "    container_name: \"{$database->uuid}\"\n";
        $yml .= "    restart: always\n";
        $yml .= "    environment:\n";
        foreach ($env as $line) {
            $yml .= "      - \"{$line}\"\n";
        }
        
        $yml .= "    networks:\n";
        $yml .= "      - vpsly_network\n";
        
        $yml .= "    volumes:\n";
        $yml .= "      - \"{$volumeName}:{$mountPath}\"\n";

        if ($database->is_public && $database->public_port) {
            $yml .= "    ports:\n";
            $yml .= "      - \"{$database->public_port}:{$internalPort}\"\n";
        }

        if ($database->limits_memory && $database->limits_memory !== '0') {
            $yml .= "    mem_limit: {$database->limits_memory}\n";
        }
        if ($database->limits_cpus && $database->limits_cpus !== '0') {
            $yml .= "    cpus: {$database->limits_cpus}\n";
        }

        $yml .= "networks:\n";
        $yml .= "  vpsly_network:\n";
        $yml .= "    external: true\n";
        $yml .= "    name: vpsly_network\n";

        $yml .= "volumes:\n";
        $yml .= "  {$volumeName}:\n";
        $yml .= "    name: \"{$volumeName}\"\n";

        return $yml;
    }
}
