<?php

namespace App\Services\Deployment;

use App\Models\Application;
use App\Events\RuntimeLogEvent;
use Illuminate\Support\Facades\Log;

class RuntimeLogService
{
    public function __construct(protected SSHService $ssh) {}

    /**
     * Récupère les X dernières lignes de logs du conteneur.
     */
    public function getLastLogs(Application $app, int $limit = 100): array
    {
        $containerName = strtolower(preg_replace('/[^a-z0-9\-]/', '-', $app->name));
        $command = "docker logs --tail {$limit} {$containerName} 2>&1";

        try {
            $output = $this->ssh->exec($command);
            return explode("\n", trim($output));
        } catch (\Exception $e) {
            Log::error("[RuntimeLog] Failed to fetch logs", [
                'app' => $app->name,
                'error' => $e->getMessage()
            ]);
            return ["Error: Could not reach container logs."];
        }
    }

    /**
     * Lance un stream SSH et diffuse chaque ligne reçue via WebSockets.
     * Note: Cette méthode est bloquante et doit être lancée dans un process séparé (Job/Command).
     */
    public function streamLogs(Application $app): void
    {
        $containerName = strtolower(preg_replace('/[^a-z0-9\-]/', '-', $app->name));
        
        // On utilise -f (follow) pour rester à l'écoute
        $command = "docker logs -f --tail 0 {$containerName} 2>&1";
        
        Log::info("[RuntimeLog] Starting stream for {$app->name}");

        $this->ssh->stream($command, function($line) use ($app) {
            if (trim($line)) {
                broadcast(new RuntimeLogEvent($app->id, $line));
            }
        });
    }
}
