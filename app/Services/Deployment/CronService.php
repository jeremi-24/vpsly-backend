<?php

namespace App\Services\Deployment;

use App\Models\Application;
use Exception;

class CronService
{
    public function __construct(protected SSHService $ssh) {}

    /**
     * Synchronise toutes les tâches planifiées de l'application sur le VPS.
     */
    public function sync(Application $app): void
    {
        try {
            \Illuminate\Support\Facades\Log::info("[CronService] Starting sync for App #{$app->id}");

            // Garantit que le serveur est chargé (fix pour le Route Model Binding)
            $server = $app->server ?? $app->load('server')->server;

            if (!$server) {
                throw new Exception("Aucun serveur configuré pour cette application.");
            }
            
            // Initialisation de la connexion SSH avec le serveur de l'application
            $this->ssh->connect($server);
            
            \Illuminate\Support\Facades\Log::info("[CronService] SSH Connected. Generating lines...");

            $lines = $this->generateCrontabLines($app);
            
            \Illuminate\Support\Facades\Log::info("[CronService] Lines generated. Updating remote crontab...");
            
            $this->updateRemoteCrontab($app, $lines);

            $app->update([
                'last_cron_synced_at' => now(),
                'last_cron_sync_error' => null,
            ]);
        } catch (Exception $e) {
            $app->update([
                'last_cron_sync_error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Génère les lignes de crontab pour l'application.
     */
    protected function generateCrontabLines(Application $app): array
    {
        $appSlug = strtolower(preg_replace('/[^a-z0-9\-]/', '-', $app->name));
        $lines = [];
        $isLegacy = $app->deployment_mode === 'legacy_existing';
        
        $targetPath = $app->target_path;
        if ($isLegacy && $app->legacy_deployment_strategy === 'professional') {
            $targetPath = rtrim($app->target_path, '/') . '/current';
        }

        // Helper pour formater la commande selon le mode
        $formatCmd = function($cmd) use ($isLegacy, $appSlug, $targetPath) {
            if ($isLegacy) {
                return "cd \"{$targetPath}\" && {$cmd}";
            }
            return "docker exec {$appSlug} {$cmd}";
        };

        // 1. Laravel Scheduler
        if ($app->has_laravel_scheduler) {
            $lines[] = "* * * * * " . $formatCmd("php artisan schedule:run") . " >> /dev/null 2>&1";
        }

        // 2. Custom Tasks
        foreach ($app->scheduledTasks()->where('is_active', true)->get() as $task) {
            $lines[] = "{$task->frequency} " . $formatCmd($task->command) . " >> /dev/null 2>&1";
        }

        return $lines;
    }

    /**
     * Met à jour la crontab distante via SSH en utilisant des marqueurs.
     */
    protected function updateRemoteCrontab(Application $app, array $newLines): void
    {
        $markerStart = "# VPSLY-START-APP-{$app->id}";
        $markerEnd = "# VPSLY-END-APP-{$app->id}";

        // 1. Récupérer la crontab actuelle
        $currentCrontab = $this->ssh->exec("crontab -l 2>/dev/null || echo \"\"");
        
        $lines = explode("\n", trim($currentCrontab));
        $cleanedLines = [];
        $insideBlock = false;
        $markerFound = false;

        // 2. Nettoyer l'ancien bloc s'il existe
        foreach ($lines as $line) {
            if (trim($line) === $markerStart) {
                $insideBlock = true;
                $markerFound = true;
                continue;
            }
            if (trim($line) === $markerEnd) {
                $insideBlock = false;
                continue;
            }
            if (!$insideBlock) {
                $cleanedLines[] = $line;
            }
        }

        // 3. Ajouter le nouveau bloc (seulement s'il y a des tâches actives)
        if (!empty($newLines)) {
            $cleanedLines[] = $markerStart;
            foreach ($newLines as $newLine) {
                $cleanedLines[] = $newLine;
            }
            $cleanedLines[] = $markerEnd;
        }

        // 4. Réinjecter la crontab
        $finalCrontab = implode("\n", array_filter($cleanedLines)) . "\n";
        
        if (trim($finalCrontab) === "") {
            $this->ssh->exec("crontab -r 2>/dev/null || true");
        } else {
            $base64 = base64_encode($finalCrontab);
            $this->ssh->exec("echo '{$base64}' | base64 -d | crontab -");
        }
    }
}
