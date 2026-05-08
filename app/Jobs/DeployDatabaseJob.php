<?php

namespace App\Jobs;

use App\Events\DatabaseStatusUpdatedEvent;
use App\Models\StandaloneDatabase;
use App\Services\Deployment\DatabaseProvisioner;
use App\Services\Deployment\LegacyDatabaseProvisioner;
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
        protected StandaloneDatabase $database
    ) {}

    /**
     * Execute the job.
     */
    public function handle(DatabaseProvisioner $dockerProvisioner, LegacyDatabaseProvisioner $legacyProvisioner): void
    {
        Log::info("[Job] Starting deployment for database: {$this->database->name}");
        $server = $this->database->server;
        
        try {
            event(new DatabaseStatusUpdatedEvent($this->database));

            if ($server->infrastructure_type === 'legacy') {
                $legacyProvisioner->provision($this->database);
            } else {
                $dockerProvisioner->provision($this->database);
            }
            
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
