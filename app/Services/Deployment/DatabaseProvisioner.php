<?php

namespace App\Services\Deployment;

use App\Models\StandaloneDatabase;
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
    public function provision(StandaloneDatabase $database): void
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
            
            $this->ssh->exec("docker network create vpsly 2>/dev/null || true");

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
    protected function generateCompose(StandaloneDatabase $database): string
    {
        $volumeName = "db-data-{$database->uuid}";
        $type = $database->type;
        
        $env = [];
        $internalPort = 5432;
        $mountPath = '/var/lib/postgresql/data';

        if ($type === 'postgres') {
            $env = [
                "POSTGRES_USER={$database->db_user}",
                "POSTGRES_PASSWORD={$database->db_password}",
                "POSTGRES_DB={$database->db_name}",
            ];
            $internalPort = 5432;
            $mountPath = '/var/lib/postgresql/data';
        } elseif ($type === 'mysql' || $type === 'mariadb') {
            $env = [
                "MYSQL_ROOT_PASSWORD={$database->db_password}",
                "MYSQL_DATABASE={$database->db_name}",
            ];

            if ($database->db_user !== 'root') {
                $env[] = "MYSQL_USER={$database->db_user}";
                $env[] = "MYSQL_PASSWORD={$database->db_password}";
            }

            $internalPort = 3306;
            $mountPath = '/var/lib/mysql';
        } elseif ($type === 'redis') {
            $env = [
                "REDIS_PASSWORD={$database->db_password}",
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
        $yml .= "      - vpsly\n";
        
        $yml .= "    volumes:\n";
        $yml .= "      - \"{$volumeName}:{$mountPath}\"\n";

        if ($database->is_public && $database->public_port) {
            $yml .= "    ports:\n";
            $yml .= "      - \"{$database->public_port}:{$internalPort}\"\n";
        }

        // Adminer (Interface de gestion)
        if ($database->has_adminer) {
            $adminerName = "adminer-{$database->uuid}";
            $yml .= "  adminer:\n";
            $yml .= "    image: \"adminer:latest\"\n";
            $yml .= "    container_name: \"{$adminerName}\"\n";
            $yml .= "    restart: always\n";
            $yml .= "    environment:\n";
            $yml .= "      - ADMINER_DEFAULT_SERVER={$database->uuid}\n";
            $yml .= "    networks:\n";
            $yml .= "      - vpsly\n";
            $yml .= "    labels:\n";
            $yml .= "      - \"traefik.enable=true\"\n";
            $serverIp = $database->server->ip;
            $yml .= "      - \"traefik.http.routers.{$adminerName}.rule=Host(`adminer-{$database->uuid}.{$serverIp}.sslip.io`)\"\n";
            $yml .= "      - \"traefik.http.routers.{$adminerName}.entrypoints=web,websecure\"\n";
            $yml .= "      - \"traefik.http.routers.{$adminerName}.tls=true\"\n";
            $yml .= "      - \"traefik.http.routers.{$adminerName}.tls.certresolver=vpsly\"\n";
            $yml .= "      - \"traefik.http.services.{$adminerName}.loadbalancer.server.port=8080\"\n";
        }

        $memoryLimit = ($database->limits_memory && $database->limits_memory !== '0') ? $database->limits_memory : '512MB';
        $cpuLimit = ($database->limits_cpus && $database->limits_cpus !== '0') ? $database->limits_cpus : '0.5';

        $yml .= "    deploy:\n";
        $yml .= "      resources:\n";
        $yml .= "        limits:\n";
        $yml .= "          memory: {$memoryLimit}\n";
        $yml .= "          cpus: '{$cpuLimit}'\n";

        $yml .= "networks:\n";
        $yml .= "  vpsly:\n";
        $yml .= "    external: true\n";
        $yml .= "    name: vpsly\n";

        $yml .= "volumes:\n";
        $yml .= "  {$volumeName}:\n";
        $yml .= "    name: \"{$volumeName}\"\n";

        return $yml;
    }
}
