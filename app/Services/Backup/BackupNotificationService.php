<?php

namespace App\Services\Backup;

use App\Models\Backup;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Http;

class BackupNotificationService
{
    /**
     * Envoie les notifications de statut de sauvegarde
     */
    public function sendNotification(Backup $backup)
    {
        $app = $backup->application;
        $user = $app->user;
        if (!$user) return;

        $statusEmoji = $backup->status === 'success' ? '✅' : '❌';
        $statusText = $backup->status === 'success' ? 'réussie' : 'échouée';
        $level = $backup->status === 'success' ? 'success' : 'error';
        
        $title = "Sauvegarde {$statusText}";
        $message = "La sauvegarde de l'application {$app->name} est {$statusText}.\nFichier: {$backup->name}";

        if ($backup->status === 'failed' && $backup->notes) {
            $message .= "\nErreur: {$backup->notes}";
        }

        $user->notify(new \App\Notifications\VpslyNotification(
            $title,
            $message,
            $level,
            $backup->type === 'database' ? 'database' : 'hard-drive',
            "/apps/{$app->id}"
        ));
    }
}
