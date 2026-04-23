<?php

namespace App\Jobs;

use App\Events\DatabaseStatusUpdatedEvent;
use App\Models\StandalonePostgresql;
use App\Services\Deployment\DatabaseProvisioner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class DeployDatabaseJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     */
    public function __construct(
        protected StandalonePostgresql $database
    ) {}

    /**
     * Execute the job.
     */
    public function handle(DatabaseProvisioner $provisioner): void
    {
        Log::info("[Job] Starting deployment for database: {$this->database->name}");
        
        try {
             // Statut initial déjà mis à jour dans le controller, mais on peut le rediffuser
            event(new DatabaseStatusUpdatedEvent($this->database));

            $provisioner->provision($this->database);
            
            $this->database->update(['status' => 'running']);
            event(new DatabaseStatusUpdatedEvent($this->database));

            Log::info("[Job] Successfully deployed database: {$this->database->name}");
        } catch (\Exception $e) {
            Log::error("[Job] Deployment failed for database {$this->database->name}: " . $e->getMessage());
            $this->database->update(['status' => 'failed']);
            event(new DatabaseStatusUpdatedEvent($this->database));
            throw $e;
        }
    }
}
