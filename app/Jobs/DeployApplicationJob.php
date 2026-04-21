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

class DeployApplicationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Timeout pour le job en secondes, car un docker build peut être long.
     */
    public $timeout = 600; 

    public function __construct(
        public int $deploymentId
    ) {}

    public function handle(DeploymentService $deploymentService): void
    {
        $deployment = Deployment::with(['application.server'])->findOrFail($this->deploymentId);
        
        // Anti-skip strict de la State Machine : on ne traite que les status 'pending'
        if ($deployment->status !== 'pending') {
            return;
        }

        // Transition pending -> running
        $deployment->update([
            'status' => 'running',
            'started_at' => now(),
        ]);

        try {
            $app = $deployment->application;
            $server = $app->server;
            
            // Lancement du flow orchestre (throws Exception on fail)
            $deploymentService->deploy($app, $server, $deployment);
            
            // Transition running -> success
            $deployment->update([
                'status' => 'success',
                'finished_at' => now(),
            ]);

        } catch (Exception $e) {
            // Transition running -> failed
            $deployment->update([
                'status' => 'failed',
                'finished_at' => now(),
            ]);
            
            // On s'assure d'échouer le job dans la queue Laravel
            $this->fail($e);
        }
    }
}
