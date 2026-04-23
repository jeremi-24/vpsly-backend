<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Queue\Queueable;

class StreamRuntimeLogsJob implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    /**
     * L'ID unique pour ce job (une seule instance par application).
     */
    public function uniqueId(): string
    {
        return (string) $this->applicationId;
    }

    /**
     * Le nombre de secondes pendant lesquelles le job peut s'exécuter.
     */
    public $timeout = 360; // Un peu plus que les 5 min de stream

    public function __construct(public int $applicationId) {}

    public function handle(\App\Services\Deployment\RuntimeLogService $logService, \App\Services\Deployment\SSHService $ssh): void
    {
        $app = \App\Models\Application::findOrFail($this->applicationId);
        
        try {
            // Connexion SSH
            $ssh->connect($app->server);
            
            // On limite le stream à 5 minutes (300 secondes) pour éviter les process fantômes
            $startTime = time();
            
            \Illuminate\Support\Facades\Log::info("[StreamJob] Starting for app {$app->id}");

            // Note: docker logs -f est bloquant. 
            // phpseclib va appeler le callback pour chaque ligne.
            $logService->streamLogs($app);

        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error("[StreamJob] Error", [
                'app' => $app->id,
                'error' => $e->getMessage()
            ]);
        } finally {
            $ssh->disconnect();
        }
    }
}
