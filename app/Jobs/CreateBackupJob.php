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
    protected $volumeId;
    protected $backupId;

    /**
     * Create a new job instance.
     */
    public function __construct(int $applicationId, ?int $databaseId = null, ?int $volumeId = null, ?int $backupId = null)
    {
        $this->applicationId = $applicationId;
        $this->databaseId = $databaseId;
        $this->volumeId = $volumeId;
        $this->backupId = $backupId;
    }

    /**
     * Execute the job.
     */
    public function handle(BackupService $backupService): void
    {
        $app = Application::findOrFail($this->applicationId);
        
        if ($this->databaseId) {
            $db = StandalonePostgresql::findOrFail($this->databaseId);
            $backupService->createDatabaseBackup($app, $db, $this->backupId);
        } elseif ($this->volumeId) {
            $volume = \App\Models\LocalPersistentVolume::findOrFail($this->volumeId);
            $backupService->createVolumeBackup($app, $volume, $this->backupId);
        } else {
            // Logique pour backup global si besoin
        }
    }
}
