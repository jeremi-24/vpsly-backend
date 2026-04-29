<?php

namespace App\Jobs;

use App\Models\Server;
use App\Services\Monitoring\ServerMonitoringService;
use App\Events\ServerMetricsEvent;
use Illuminate\Bus\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class MonitorServerJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, SerializesModels;

    public function __construct(protected Server $server) {}

    public function handle(ServerMonitoringService $monitoring)
    {
        $dockerStats = $monitoring->getDockerStats($this->server);
        $systemStats = $monitoring->getSystemStats($this->server);

        // --- GESTION DES ALERTES AVEC THROTTLE ---
        $cacheKeyPrefix = "server_alert_{$this->server->id}_";
        $cooldown = 3600 * 6; // 6 heures de silence entre deux alertes identiques

        // 1. Serveur inaccessible
        if (empty($systemStats)) {
            if (!\Illuminate\Support\Facades\Cache::has($cacheKeyPrefix . 'inaccessible')) {
                $this->server->user->notify(new \App\Notifications\VpslyNotification(
                    "Serveur inaccessible",
                    "Le serveur {$this->server->name} ({$this->server->ip}) ne répond plus aux requêtes SSH.",
                    "error",
                    "server"
                ));
                \Illuminate\Support\Facades\Cache::put($cacheKeyPrefix . 'inaccessible', true, $cooldown);
            }
        } else {
            // Reset de l'alerte d'inaccessibilité si le serveur revient
            \Illuminate\Support\Facades\Cache::forget($cacheKeyPrefix . 'inaccessible');

            // 2. Disque Critique
            $diskUsage = intval(str_replace('%', '', $systemStats['disk']['disk_usage'] ?? '0'));
            if ($diskUsage > 80) {
                if (!\Illuminate\Support\Facades\Cache::has($cacheKeyPrefix . 'disk')) {
                    $this->server->user->notify(new \App\Notifications\VpslyNotification(
                        "Disque Critique",
                        "Le disque sur {$this->server->name} est rempli à {$diskUsage}%.",
                        "warning",
                        "hard-drive"
                    ));
                    \Illuminate\Support\Facades\Cache::put($cacheKeyPrefix . 'disk', true, $cooldown);
                }
            } else {
                \Illuminate\Support\Facades\Cache::forget($cacheKeyPrefix . 'disk');
            }

            // 3. RAM Critique
            $memUsed = $systemStats['memory']['mem_used'] ?? 0;
            $memTotal = $systemStats['memory']['mem_total'] ?? 1;
            $memPerc = ($memUsed / $memTotal) * 100;
            if ($memPerc > 90) {
                if (!\Illuminate\Support\Facades\Cache::has($cacheKeyPrefix . 'ram')) {
                    $this->server->user->notify(new \App\Notifications\VpslyNotification(
                        "RAM Critique",
                        "La mémoire vive sur {$this->server->name} est utilisée à " . round($memPerc, 1) . "%.",
                        "warning",
                        "cpu"
                    ));
                    \Illuminate\Support\Facades\Cache::put($cacheKeyPrefix . 'ram', true, $cooldown);
                }
            } else {
                \Illuminate\Support\Facades\Cache::forget($cacheKeyPrefix . 'ram');
            }
        }

        event(new ServerMetricsEvent($this->server->id, [
            'docker' => $dockerStats,
            'system' => $systemStats,
            'timestamp' => now()->toIso8601String()
        ]));
    }
}
