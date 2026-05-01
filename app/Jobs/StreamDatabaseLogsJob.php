<?php

namespace App\Jobs;

use App\Models\StandaloneDatabase;
use App\Services\Deployment\RuntimeLogService;
use App\Services\Deployment\SSHService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class StreamDatabaseLogsJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * L'ID unique pour ce job (une seule instance par base de données).
     */
    public function uniqueId(): string
    {
        return (string) $this->databaseId;
    }

    /**
     * Le temps maximal d'exécution du Job (15 minutes par exemple).
     */
    public $timeout = 900;

    /**
     * Create a new job instance.
     */
    public function __construct(
        protected int $databaseId
    ) {}

    /**
     * Execute the job.
     */
    public function handle(SSHService $ssh, RuntimeLogService $logService): void
    {
        $database = StandaloneDatabase::find($this->databaseId);

        if (!$database || !$database->server) {
            Log::error("[StreamDatabaseLogs] Database or server not found", ['id' => $this->databaseId]);
            return;
        }

        try {
            $ssh->connect($database->server);
            
            Log::info("[StreamDatabaseLogs] Starting log stream for database: {$database->name}");
            
            // On définit le canal spécifique pour les bases de données
            $channelName = "database.{$database->id}.runtime-logs";
            
            $logService->streamLogs($database, $channelName);
            
        } catch (\Exception $e) {
            Log::error("[StreamDatabaseLogs] Error during log streaming", [
                'database' => $database->name,
                'error' => $e->getMessage()
            ]);
        } finally {
            $ssh->disconnect();
        }
    }
}
