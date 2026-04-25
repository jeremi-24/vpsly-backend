<?php

namespace App\Jobs;

use App\Models\Application;
use App\Models\StandalonePostgresql;
use App\Services\Deployment\BackupService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class CreateBackupJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;
    public $backoff = 60;

    protected $applicationId;
    protected $databaseId;

    /**
     * Create a new job instance.
     */
    public function __construct(int $applicationId, ?int $databaseId = null)
    {
        $this->applicationId = $applicationId;
        $this->databaseId = $databaseId;
    }

    /**
     * Execute the job.
     */
    public function handle(BackupService $backupService): void
    {
        $app = Application::findOrFail($this->applicationId);
        
        if ($this->databaseId) {
            $db = StandalonePostgresql::findOrFail($this->databaseId);
            $backupService->createDatabaseBackup($app, $db);
        } else {
            // Logique pour backup de toute l'app (volumes, etc) à venir
        }
    }
}
