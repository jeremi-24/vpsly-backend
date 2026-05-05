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

            $team = $app->team;
            if (!$team) continue;

            Log::info("Vérification App: {$app->name} (Team: {$team->name})");

            // Si le plan ne supporte pas les backups auto, on passe
            if (!$team->hasFeature('auto_backups')) {
                Log::info("  - Backup auto non supporté pour le plan {$team->plan}.");
                continue;
            }

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

        // Utilisation de Carbon pour une comparaison d'objets DateTime fiable
        try {
            $scheduled = $now->copy()->setTimeFromTimeString($executionTime);
        } catch (\Exception $e) {
            Log::error("Format d'heure invalide pour le backup : {$executionTime}");
            return false;
        }

        // Si l'heure prévue n'est pas encore atteinte, on ne fait rien
        if ($now->lt($scheduled)) return false;

        // On vérifie la fréquence par rapport au dernier backup réussi
        $lastBackup = $app->backups()->where('status', 'success')->latest()->first();
        
        if (!$lastBackup) return true; // Premier backup réussi à faire

        switch ($frequency) {
            case 'hourly':
                // Au moins 60 minutes depuis le dernier backup
                return $lastBackup->created_at->diffInMinutes($now) >= 60;
            case 'daily':
                // Pas encore de backup aujourd'hui
                return !$lastBackup->created_at->isToday();
            case 'weekly':
                // Au moins 7 jours depuis le dernier backup
                return $lastBackup->created_at->diffInDays($now) >= 7;
            default:
                return false;
        }
    }
}
