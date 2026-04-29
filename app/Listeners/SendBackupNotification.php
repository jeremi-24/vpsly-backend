<?php

namespace App\Listeners;

use App\Events\BackupUpdatedEvent;
use App\Services\Backup\BackupNotificationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

class SendBackupNotification implements ShouldQueue
{
    /**
     * Le service de notification.
     */
    protected $notificationService;

    /**
     * Create the event listener.
     */
    public function __construct(BackupNotificationService $notificationService)
    {
        $this->notificationService = $notificationService;
    }

    /**
     * Handle the event.
     */
    public function handle(BackupUpdatedEvent $event): void
    {
        $backup = $event->backup;

        // On n'envoie la notification que si le backup est terminé (succès ou échec)
        if (in_array($backup->status, ['success', 'failed'])) {
            Log::info("Déclenchement de la notification pour le backup {$backup->id}");
            $this->notificationService->sendNotification($backup);
        }
    }
}
