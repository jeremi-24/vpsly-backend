<?php

namespace App\Services;

use App\Models\Server;
use App\Models\Application;
use Illuminate\Support\Facades\Cache;
use App\Events\ServerStatsUpdated;

class MonitoringService
{
    public function __construct(
        protected SshService $ssh
    ) {}

    /**
     * Récupère les stats d'un serveur via l'agent Go.
     */
    public function getServerStats(Server $server): array
    {
        $cacheKey = "server_stats_{$server->id}";
        $lockKey = "lock_server_stats_{$server->id}";

        return Cache::remember($cacheKey, 5, function () use ($server, $lockKey) {
            // Utilisation d'un verrou pour éviter le cache stampede et les connexions SSH parallèles
            return \Illuminate\Support\Facades\Cache::lock($lockKey, 10)->get(function () use ($server) {
                try {
                    $this->ssh->connect($server);
                    $output = $this->ssh->exec("vpsly-agent stats");
                    
                    $data = json_decode($output, true);
                    
                    if (json_last_error() !== JSON_ERROR_NONE) {
                        \Illuminate\Support\Facades\Log::error("Failed to decode monitoring stats for server {$server->id}", [
                            'output' => $output,
                            'error' => json_last_error_msg()
                        ]);
                        return ['error' => 'Invalid agent output'];
                    }

                    return $data;
                } catch (\Exception $e) {
                    \Illuminate\Support\Facades\Log::warning("Monitoring failed for server {$server->id}: " . $e->getMessage());
                    return ['error' => $e->getMessage()];
                }
            }) ?: ['error' => 'Could not acquire lock'];
        });
    }

    /**
     * Force le rafraîchissement des stats et broadcast via WebSockets.
     */
    public function refreshAndBroadcast(Server $server): array
    {
        $lockKey = "lock_server_stats_{$server->id}";
        $cacheKey = "server_stats_{$server->id}";

        $stats = \Illuminate\Support\Facades\Cache::lock($lockKey, 10)->get(function () use ($server, $cacheKey) {
            try {
                $this->ssh->connect($server);
                $output = $this->ssh->exec("vpsly-agent stats");
                $data = json_decode($output, true);
                
                if (json_last_error() === JSON_ERROR_NONE) {
                    Cache::put($cacheKey, $data, 5);
                    broadcast(new ServerStatsUpdated($server, $data));
                    return $data;
                }
                return ['error' => 'Invalid output'];
            } catch (\Exception $e) {
                return ['error' => $e->getMessage()];
            }
        });

        return $stats ?: ['error' => 'Could not acquire lock'];
    }

    /**
     * Déploie et compile l'agent sur le serveur.
     */
    public function deployAgent(Server $server): string
    {
        // TODO: Implémenter le transfert sécurisé du binaire compilé ou du source
        throw new \RuntimeException("L'auto-déploiement de l'agent n'est pas encore implémenté.");
    }
}
