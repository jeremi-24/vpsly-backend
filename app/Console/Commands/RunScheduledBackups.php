<?php

namespace App\Console\Commands;

use App\Models\Application;
use App\Models\BackupSetting;
use App\Models\ApplicationBackupOverride;
use App\Jobs\CreateBackupJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class RunScheduledBackups extends Command
{
    /**
     * Le nom et la signature de la commande.
     */
    protected $signature = 'vpsly:backups:run';

    /**
     * La description de la commande.
     */
    protected $description = 'Vérifie et lance les sauvegardes programmées selon les réglages utilisateur';

    /**
     * Exécuter la commande.
     */
    public function handle()
    {
        $this->info('Vérification des sauvegardes programmées...');

        // On récupère toutes les applications qui ont des bases de données ou des volumes
        $applications = Application::with(['databases', 'persistentVolumes', 'user.backupSettings'])->get();

        foreach ($applications as $app) {
            $user = $app->user;
            if (!$user) continue;

            // 1. Récupérer la configuration effective (Override > Global)
            $globalSettings = $user->backupSettings()->first();
            $override = ApplicationBackupOverride::where('application_id', $app->id)->first();

            Log::info("Vérification App: {$app->name} (User: {$user->email})");

            // Si désactivé au niveau app, on passe
            if ($override && !$override->is_enabled) {
                Log::info("  - Backup désactivé par override pour cette app.");
                continue;
            }
            
            // Si pas de settings globaux et pas d'active, on passe
            if (!$globalSettings || !$globalSettings->active) {
                Log::info("  - Pas de réglages globaux actifs pour l'utilisateur.");
                continue;
            }

            $frequency = $override->frequency_override ?? $globalSettings->frequency;
            $executionTime = $globalSettings->execution_time; // Format H:i
            $currentTime = now()->format('H:i');

            Log::info("  - Config: Freq={$frequency}, HeureReglée={$executionTime}, HeureActuelle={$currentTime}");

            // 2. Vérifier si c'est le moment de lancer
            if ($this->shouldRunBackup($app, $frequency, $executionTime)) {
                Log::info("  - [ACTION] C'est l'heure !");
                $this->line("Lancement de la sauvegarde pour l'application : {$app->name}");
                
                if ($app->databases->isEmpty() && $app->persistentVolumes->isEmpty()) {
                    Log::warning("  - Mais aucun composant (DB/Volume) trouvé pour {$app->name}.");
                }
                
                // Lancer un backup pour chaque DB
                foreach ($app->databases as $db) {
                    CreateBackupJob::dispatch($app->id, $db->id, null);
                }

                // Lancer un backup pour chaque volume (si configuré)
                foreach ($app->persistentVolumes as $volume) {
                    CreateBackupJob::dispatch($app->id, null, $volume->id);
                }
            }
        }

        $this->info('Vérification terminée.');
    }

    /**
     * Détermine si le backup doit être lancé maintenant
     */
    protected function shouldRunBackup($app, $frequency, $executionTime)
    {
        if ($frequency === 'manual') return false;

        $now = now();
        $currentTime = $now->format('H:i');

        // On vérifie si l'heure d'exécution est passée
        if ($currentTime < $executionTime) return false;

        // On vérifie la fréquence
        $lastBackup = $app->backups()->latest()->first();
        if (!$lastBackup) return true; // Premier backup

        switch ($frequency) {
            case 'hourly':
                return $lastBackup->created_at->diffInHours($now) >= 1;
            case 'daily':
                return !$lastBackup->created_at->isToday();
            case 'weekly':
                return $lastBackup->created_at->diffInDays($now) >= 7;
            default:
                return false;
        }
    }
}
