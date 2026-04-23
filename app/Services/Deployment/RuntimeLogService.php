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
        if ($resource instanceof \App\Models\StandalonePostgresql) {
            return $resource->uuid;
        }
        
        return strtolower(preg_replace('/[^a-z0-9\-]/', '-', $resource->name));
    }

    /**
     * Récupère les X dernières lignes de logs du conteneur.
     */
    public function getLastLogs($resource, int $limit = 100): array
    {
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
            return ["Error: Could not reach container logs."];
        }
    }

    /**
     * Lance un stream SSH et diffuse chaque ligne reçue via WebSockets.
     */
    public function streamLogs($resource, string $channelName = null): void
    {
        $containerName = $this->getContainerName($resource);
        $command = "docker logs -f --tail 0 {$containerName} 2>&1";
        
        $channelName = $channelName ?? 'application.' . $resource->id;

        Log::info("[RuntimeLog] Starting stream for {$resource->name} (Container: {$containerName})");

        $this->ssh->stream($command, function($line) use ($resource, $channelName) {
            if (trim($line)) {
                broadcast(new RuntimeLogEvent($resource->id, $line, $channelName));
            }
        });
    }
}
