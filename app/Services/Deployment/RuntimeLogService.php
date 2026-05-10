<?php

namespace App\Services\Deployment;

use App\Models\Application;
use App\Events\RuntimeLogEvent;
use Illuminate\Support\Facades\Log;

class RuntimeLogService
{
    public function __construct(protected SSHService $ssh) {}

    /**
     * Récupère le nom du conteneur en fonction du type de ressource.
     */
    protected function getContainerName($resource): string
    {
        if ($resource instanceof \App\Models\StandaloneDatabase) {
            return $resource->uuid;
        }
        
        return strtolower(preg_replace('/[^a-z0-9\-]/', '-', $resource->name));
    }

    /**
     * Récupère les X dernières lignes de logs du conteneur.
     */
    public function getLastLogs($resource, int $limit = 100): array
    {
        // CAS 1: Application Legacy
        if ($resource instanceof Application && ($resource->server->infrastructure_type === 'legacy')) {
            if ($resource->log_command) {
                try {
                    // On retire le flag -f (follow) pour s'assurer que la commande s'arrête
                    $safeCommand = str_replace('-f', '-n ' . $limit, $resource->log_command);
                    $output = $this->ssh->exec("cd \"{$resource->target_path}\" && {$safeCommand}");
                    return explode("\n", trim($output));
                } catch (\Exception $e) {
                    return ["Erreur lors de l'exécution de la commande de logs : " . $e->getMessage()];
                }
            }
            return ["Logs système non disponibles. Veuillez configurer une 'Commande de logs' (ex: tail -f storage/logs/laravel.log) pour cette application."];
        }

        // CAS 2: Base de données Legacy
        if ($resource instanceof \App\Models\StandaloneDatabase && ($resource->server->infrastructure_type === 'legacy')) {
            $logPath = match($resource->type) {
                'mysql', 'mariadb' => '/var/log/mysql/error.log',
                'postgres' => '/var/log/postgresql/postgresql-*.log',
                'redis' => '/var/log/redis/redis-server.log',
                default => null
            };

            if ($logPath) {
                try {
                    // On tente de lire les logs système (peut nécessiter des droits que l'utilisateur a via sudo)
                    $output = $this->ssh->exec("sudo tail -n {$limit} {$logPath} 2>/dev/null || echo 'Accès aux logs système restreint ou fichier introuvable ({$logPath})'");
                    return explode("\n", trim($output));
                } catch (\Exception $e) {
                    return ["Impossible de lire les logs système de la base de données."];
                }
            }
            return ["Logs non disponibles pour ce type de base de données en mode Legacy."];
        }

        // CAS 3: Docker (Par défaut)
        $containerName = $this->getContainerName($resource);
        $command = "docker logs --tail {$limit} {$containerName} 2>&1";

        try {
            $output = $this->ssh->exec($command);
            return explode("\n", trim($output));
        } catch (\Exception $e) {
            Log::error("[RuntimeLog] Failed to fetch logs", [
                'resource' => $resource->name,
                'error' => $e->getMessage()
            ]);
            return ["Error: le conteneur n'est pas joignable ou n'existe pas en mode Docker."];
        }
    }


    /**
     * Lance un stream SSH et diffuse chaque ligne reçue via WebSockets.
     */
    public function streamLogs($resource, ?string $channelName = null): void
    {
        // CAS 1: Application Legacy
        if ($resource instanceof Application && ($resource->server->infrastructure_type === 'legacy')) {
            if (!$resource->log_command) {
                Log::info("[RuntimeLog] Skipping stream for legacy application (no log command): {$resource->name}");
                return;
            }
            $command = "cd \"{$resource->target_path}\" && {$resource->log_command}";
        } 
        // CAS 2: Base de données Legacy
        elseif ($resource instanceof \App\Models\StandaloneDatabase && ($resource->server->infrastructure_type === 'legacy')) {
            $logPath = match($resource->type) {
                'mysql', 'mariadb' => '/var/log/mysql/error.log',
                'postgres' => '/var/log/postgresql/postgresql-*.log',
                'redis' => '/var/log/redis/redis-server.log',
                default => null
            };

            if (!$logPath) {
                Log::info("[RuntimeLog] No log path defined for legacy DB type: {$resource->type}");
                return;
            }
            
            $command = "sudo tail -f -n 0 {$logPath} 2>/dev/null";
        }
        // CAS 3: Docker (Par défaut)
        else {
            $containerName = $this->getContainerName($resource);
            $command = "docker logs -f --tail 0 {$containerName} 2>&1";
        }

        
        $channelName = $channelName ?? 'application.' . $resource->id;

        Log::info("[RuntimeLog] Starting stream for {$resource->name} (Command: {$command})");

        $this->ssh->stream($command, function($line) use ($resource, $channelName) {
            if (trim($line)) {
                try {
                    broadcast(new RuntimeLogEvent($resource->id, $line, $channelName));
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::error("[RuntimeLog] Broadcast failed: " . $e->getMessage());
                }
            }
        });
    }
}
