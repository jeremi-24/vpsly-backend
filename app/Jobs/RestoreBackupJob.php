<?php

namespace App\Jobs;

use App\Models\Backup;
use App\Services\Deployment\BackupService;
use App\Events\BackupUpdatedEvent;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class RestoreBackupJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $backupId;
    public int $timeout = 600; // 10 minutes

    /**
     * Create a new job instance.
     */
    public function __construct(int $backupId)
    {
        $this->backupId = $backupId;
    }

    /**
     * Execute the job.
     */
    public function handle(BackupService $backupService): void
    {
        $backup = Backup::find($this->backupId);
        if (!$backup) return;

        try {
            Log::info("Démarrage du Job de restauration pour le backup {$backup->id}");
            $backup->update(['status' => 'restoring']);
            event(new BackupUpdatedEvent($backup));

            $backupService->restore($backup);

            $backup->update(['status' => 'success']);
            Log::info("Job: Restauration réussie pour le backup {$backup->id}");
        } catch (\Exception $e) {
            Log::error("Job: Échec de la restauration pour le backup {$backup->id} : " . $e->getMessage());
            $backup->update([
                'status' => 'failed',
                'notes' => ($backup->notes ? $backup->notes . "\n" : "") . "Erreur restauration: " . $e->getMessage()
            ]);
        } finally {
            $backup->refresh();
            event(new BackupUpdatedEvent($backup));
        }
    }
}
