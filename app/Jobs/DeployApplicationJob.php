<?php

namespace App\Jobs;

use App\Models\Application;
use App\Models\Deployment;
use App\Models\Server;
use App\Services\DeploymentService;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class DeployApplicationJob implements \Illuminate\Contracts\Queue\ShouldQueue, \Illuminate\Contracts\Queue\ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Nombre de tentatives avant échec définitif.
     */
    public $tries = 3;

    /**
     * Temps d'attente entre chaque retry (en secondes).
     */
    public function backoff(): array
    {
        return [10, 30, 60];
    }

    /**
     * Identifiant unique pour éviter les déploiements concurrents d'une même app.
     */
    public function uniqueId(): string
    {
        return (string) $this->deploymentId; // ID du déploiement ou de l'app ?
        // On préfère l'ID du déploiement car le controller rejette déjà via is_deploying.
    }

    /**
     * Timeout pour le job en secondes, car un docker build peut être long.
     */
    public $timeout = 600; 

    public function __construct(
        public int $deploymentId
    ) {}

    public function handle(\App\Services\Deployment\DeploymentOrchestrator $orchestrator): void
    {
        $deployment = Deployment::with(['application.user', 'application.server'])->findOrFail($this->deploymentId);
        
        // Anti-skip : on ne traite que les status 'pending' au démarrage (sécurité supplémentaire)
        if ($deployment->status !== \App\Enums\DeploymentStatus::PENDING->value && $this->attempts() === 1) {
            return;
        }

        try {
            $orchestrator->deploy($deployment->application, $deployment->application->server, $deployment);
        } catch (\App\Exceptions\Deployment\NonRetryableException $e) {
            // En cas d'erreur non-retryable (Docker build, config incorrecte), on échoue immédiatement.
            $this->fail($e);
        } catch (\Exception $e) {
             // En cas d'erreur temporaire (SSH timeout, réseau), Laravel retentera (tries = 3).
            throw $e;
        }
    }
}
