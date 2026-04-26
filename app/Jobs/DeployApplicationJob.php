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

class DeployApplicationJob implements \Illuminate\Contracts\Queue\ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Nombre de tentatives avant échec définitif.
     */
    public $tries = 1;

    /**
     * Temps d'attente entre chaque retry (en secondes).
     */
    public function backoff(): array
    {
        return [10];
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
        $deployment = \App\Models\Deployment::with(['application.user', 'application.server'])->findOrFail($this->deploymentId);
        
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

    /**
     * Nettoyage de secours si le job échoue définitivement.
     */
    public function failed(\Throwable $exception): void
    {
        $deployment = \App\Models\Deployment::find($this->deploymentId);
        if (!$deployment) return;

        $app = $deployment->application;
        if (!$app) return;

        // Reset de l'état de l'application
        $app->update([
            'is_deploying' => false,
            'status' => \App\Enums\DeploymentStatus::FAILED->value,
        ]);

        // Mise à jour du déploiement
        $deployment->update([
            'status' => \App\Enums\DeploymentStatus::FAILED->value,
            'finished_at' => now(),
        ]);

        // Notification frontend
        event(new \App\Events\DeploymentStatusUpdatedEvent(
            $deployment->id,
            $app->id,
            \App\Enums\DeploymentStatus::FAILED->value,
            false
        ));
    }
}
