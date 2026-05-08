<?php

namespace App\Services\Deployment;

use App\Models\Application;
use App\Models\Backup;
use App\Models\StandaloneDatabase;
use App\Services\Deployment\SSHService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class BackupService
{
    protected SSHService $ssh;

    public function __construct(SSHService $ssh)
    {
        $this->ssh = $ssh;
    }

    /**
     * Crée une sauvegarde d'une base de données liée à une application.
     */
    public function createDatabaseBackup(Application $app, StandaloneDatabase $db, ?int $backupId = null): Backup
    {
        $this->ssh->connect($app->server);

        $backupId_str = Str::random(4);
        $safeDbName = preg_replace('/[^a-z0-9\-_]/i', '_', $db->name);
        $filename = "db_{$safeDbName}_" . now()->format('d-m-Y_His') . "_{$backupId_str}.sql";
        $backupDir = "/var/www/vpsly/backups/{$app->id}";
        $backupPath = "{$backupDir}/{$filename}";

        // Créer le dossier de backup s'il n'existe pas
        $this->ssh->exec("mkdir -p {$backupDir}");

        if ($backupId) {
            $backup = Backup::findOrFail($backupId);
            $backup->update([
                'name' => $filename,
                'path' => $backupPath,
                'status' => 'pending',
                'team_id' => $app->team_id,
            ]);
        } else {
            $backup = Backup::create([
                'application_id' => $app->id,
                'database_id' => $db->id,
                'name' => $filename,
                'type' => 'db',
                'status' => 'pending',
                'path' => $backupPath,
                'team_id' => $app->team_id,
            ]);
        }

        try {
            $type = $db->type;
            $isLegacy = $app->server->infrastructure_type === 'legacy';
            
            if ($type === 'mysql' || $type === 'mariadb') {
                $dbUser = escapeshellarg($db->db_user);
                $dbName = escapeshellarg($db->db_name);
                $dbPass = $db->db_password;
                
                if ($isLegacy) {
                    // Backup système natif
                    $dumpCmd = "MYSQL_PWD='{$dbPass}' mysqldump --no-tablespaces -u {$dbUser} {$dbName} > {$backupPath}";
                } else {
                    // Approche Sidecar Docker
                    $dbHost = escapeshellarg($db->uuid);
                    $dumpCmd = "bash -c \"docker run --rm --network vpsly " .
                               "-e MYSQL_PWD='{$dbPass}' " .
                               "mysql:8.4 " .
                               "mysqldump --no-tablespaces -h {$dbHost} -u {$dbUser} {$dbName} > {$backupPath}\"";
                }
            } else {
                // Postgres
                $dbUser = escapeshellarg($db->db_user);
                $dbName = escapeshellarg($db->db_name);
                $dbPass = $db->db_password;

                if ($isLegacy) {
                    $dumpCmd = "PGPASSWORD='{$dbPass}' pg_dump -U {$dbUser} {$dbName} > {$backupPath}";
                } else {
                    $dbHost = escapeshellarg($db->uuid);
                    $dumpCmd = "bash -c \"docker run --rm --network vpsly " .
                               "-e PGPASSWORD='{$dbPass}' " .
                               "postgres:16 " .
                               "pg_dump -h {$dbHost} -U {$dbUser} {$dbName} > {$backupPath}\"";
                }
            }
            
            // Masquer le mot de passe dans les logs
            $maskedCmd = preg_replace('/(PASSWORD|MYSQL_PWD)=[\'"].*?[\'"]/', '$1=\'********\'', $dumpCmd);
            Log::info("Running backup command: {$maskedCmd}");
            $this->ssh->exec($dumpCmd);

            // Compresser le backup
            $this->ssh->exec("gzip {$backupPath}");
            $finalPath = "{$backupPath}.gz";
            $finalName = "{$filename}.gz";

            // Calculer la taille
            $sizeOutput = $this->ssh->exec("stat -c%s {$finalPath}");
            $size = (int) trim($sizeOutput);

            $backup->update([
                'status' => 'success',
                'size' => $size,
                'path' => $finalPath,
                'name' => $finalName,
            ]);

            // --- NOUVEAU : Exportation externe ---
            $this->exportToExternalStorage($backup, $app);
            // ------------------------------------

            $backup->refresh();
            Log::info("L'événement de mise à jour de sauvegarde a été diffusé (Succès) pour l'ID de sauvegarde : {$backup->id}");
            event(new \App\Events\BackupUpdatedEvent($backup));

            return $backup;

        } catch (\Exception $e) {
            Log::error("Sauvegarde de {$app->name} a échoué : " . $e->getMessage());
            $backup->update([
                'status' => 'failed',
                'notes' => $e->getMessage()
            ]);

            $backup->refresh();
            Log::info("L'événement de mise à jour de sauvegarde a été diffusé (Échec) pour l'ID de sauvegarde : {$backup->id}");
            event(new \App\Events\BackupUpdatedEvent($backup));
            
            throw $e;
        } finally {
            $this->ssh->disconnect();
        }
    }

    /**
     * Crée une sauvegarde d'un volume persistant d'une application.
     */
    public function createVolumeBackup(Application $app, \App\Models\LocalPersistentVolume $volume, ?int $backupId = null): Backup
    {
        $this->ssh->connect($app->server);

        $backupId_str = Str::random(4);
        $safeVolName = preg_replace('/[^a-z0-9\-_]/i', '_', $volume->name ?? 'vol');
        $filename = "vol_{$safeVolName}_" . now()->format('d-m-Y_His') . "_{$backupId_str}.tar.gz";
        $backupDir = "/var/www/vpsly/backups/{$app->id}";
        $backupPath = "{$backupDir}/{$filename}";

        $this->ssh->exec("mkdir -p {$backupDir}");

        if ($backupId) {
            $backup = Backup::findOrFail($backupId);
            $backup->update([
                'name' => $filename,
                'path' => $backupPath,
                'status' => 'pending',
                'notes' => "Volume: {$volume->mount_path}",
                'team_id' => $app->team_id,
            ]);
        } else {
            $backup = Backup::create([
                'application_id' => $app->id,
                'name' => $filename,
                'type' => 'volume',
                'status' => 'pending',
                'path' => $backupPath,
                'notes' => "Volume: {$volume->mount_path}",
                'team_id' => $app->team_id,
            ]);
        }

        try {
            $hostPath = $volume->host_path;
            $isLegacy = $app->server->infrastructure_type === 'legacy';
            
            if ($isLegacy) {
                if ($app->legacy_deployment_strategy === 'professional') {
                    // En Pro, les données persistantes sont TOUJOURS dans shared
                    $mountPath = ltrim($volume->mount_path, '/');
                    $hostPath = rtrim($app->target_path, '/') . "/shared/{$mountPath}";
                } else {
                    // En Simple, on prend le host_path tel quel s'il est absolu, sinon relatif au target_path
                    if ($hostPath && !str_starts_with($hostPath, '/')) {
                        $hostPath = rtrim($app->target_path, '/') . '/' . ltrim($hostPath, '/');
                    } elseif (!$hostPath) {
                        // Fallback : mount_path relatif au target_path
                        $hostPath = rtrim($app->target_path, '/') . '/' . ltrim($volume->mount_path, '/');
                    }
                }
            } else {
                // Si c'est un volume nommé Docker (pas de host_path), on utilise le chemin Docker par défaut
                if (!$hostPath) {
                    $appSlug = preg_replace('/[^a-z0-9\-]/', '-', strtolower($app->name));
                    $hostPath = "/var/lib/docker/volumes/{$appSlug}_{$volume->name}/_data";
                }
            }

            // Vérifie si le dossier existe avant
            $checkDir = $this->ssh->exec("if [ -d \"{$hostPath}\" ]; then echo \"exists\"; fi");
            if (trim($checkDir) !== 'exists') {
                throw new \Exception("Le dossier source {$hostPath} n'existe pas sur le serveur. Assurez-vous que l'application a été déployée au moins une fois avec ce volume.");
            }

            $tarCmd = "tar -czf {$backupPath} -C {$hostPath} .";
            $this->ssh->exec($tarCmd);

            // Calculer la taille
            $sizeOutput = $this->ssh->exec("stat -c%s {$backupPath}");
            $size = (int) trim($sizeOutput);

            $backup->update([
                'status' => 'success',
                'size' => $size,
            ]);

            // --- NOUVEAU : Exportation externe ---
            $this->exportToExternalStorage($backup, $app);
            // ------------------------------------

            $backup->refresh();
            Log::info("Broadcasting BackupUpdatedEvent (Success - Volume) for Backup ID: {$backup->id}");
            event(new \App\Events\BackupUpdatedEvent($backup));

            return $backup;

        } catch (\Exception $e) {
            Log::error("Volume backup failed for app {$app->name}: " . $e->getMessage());
            $backup->update([
                'status' => 'failed',
                'notes' => $e->getMessage()
            ]);

            $backup->refresh();
            Log::info("Broadcasting BackupUpdatedEvent (Failed - Volume) for Backup ID: {$backup->id}");
            event(new \App\Events\BackupUpdatedEvent($backup));

            throw $e;
        } finally {
            $this->ssh->disconnect();
        }
    }

    /**
     * Gère l'exportation vers un stockage externe si configuré.
     */
    protected function exportToExternalStorage(Backup $backup, Application $app)
    {
        $settings = \App\Models\BackupSetting::where('team_id', $app->team_id)->first();
        if (!$settings || $settings->storage_destination === 'local') {
            return;
        }

        if ($settings->storage_destination === 'google_drive') {
            try {
                Log::info("Démarrage de l'exportation Google Drive pour le backup {$backup->id}");
                
                // 1. Créer le service avec l'objet settings pour permettre le rafraîchissement
                $driveService = new \App\Services\Backup\GoogleDriveService($settings);

                // 2. Télécharger le fichier du VPS vers le backend temporairement
                $tempPath = storage_path("app/temp/" . $backup->name);
                if (!file_exists(dirname($tempPath))) {
                    mkdir(dirname($tempPath), 0755, true);
                }

                Log::info("Migration Cloud : TÃ©lÃ©chargement distant vers local ({$tempPath})");
                $this->ssh->downloadToFile($backup->path, $tempPath);

                // 3. Upload vers Drive (dans vpsly_backups/{appName}/)
                $driveId = $driveService->uploadFile($tempPath, $backup->name, $app->name);

                // 4. Mettre à jour le backup avec l'ID Drive et vider le path local
                $oldPath = $backup->path;
                $backup->update([
                    'notes' => ($backup->notes ? $backup->notes . "\n" : "") . "Google Drive ID: {$driveId}",
                    'path' => null, // On indique que le fichier n'est plus sur le VPS
                ]);

                // 5. Supprimer le fichier sur le serveur distant (VPS)
                if ($oldPath) {
                    $this->ssh->exec("rm {$oldPath}");
                }

                // 6. Nettoyer le fichier temporaire local (sur le backend VPSly)
                unlink($tempPath);

                Log::info("Exportation Google Drive réussie et fichier local supprimé pour le backup {$backup->id}");

            } catch (\Exception $e) {
                Log::error("Échec de l'exportation Google Drive : " . $e->getMessage());
                $backup->update([
                    'notes' => ($backup->notes ? $backup->notes . "\n" : "") . "Échec Drive: " . $e->getMessage()
                ]);
            }
        }
    }

    /**
     * Restaure une sauvegarde (Base de données ou Volume).
     */
    public function restore(Backup $backup)
    {
        $app = $backup->application;
        $this->ssh->connect($app->server);

        try {
            // 1. Préparer le fichier sur le VPS
            $restorePath = $backup->path;
            $isTempFile = false;

            if (!$restorePath) {
                // Le fichier est sur Drive, on doit le télécharger sur le VPS
                Log::info("Restauration : Téléchargement depuis Drive pour le backup {$backup->id}");
                $restorePath = $this->prepareFileFromDrive($backup);
                $isTempFile = true;
            }

            // 2. Exécuter la restauration selon le type
            if ($backup->type === 'db') {
                $this->restoreDatabase($backup, $restorePath);
            } else {
                $this->restoreVolume($backup, $restorePath);
            }

            // 3. Nettoyage si fichier temporaire
            if ($isTempFile) {
                $this->ssh->exec("rm {$restorePath}");
            }

            Log::info("Restauration réussie pour le backup {$backup->id}");
            return true;

        } catch (\Exception $e) {
            Log::error("Échec de la restauration {$backup->id} : " . $e->getMessage());
            throw $e;
        } finally {
            $this->ssh->disconnect();
        }
    }

    /**
     * Télécharge un fichier depuis Drive vers le VPS pour restauration
     */
    protected function prepareFileFromDrive(Backup $backup): string
    {
        $app = $backup->application;
        $settings = \App\Models\BackupSetting::where('team_id', $app->team_id)->first();
        if (!$settings) throw new \Exception("Réglages de sauvegarde introuvables pour cette équipe.");

        // Extraire l'ID Drive des notes
        preg_match('/Google Drive ID: ([a-zA-Z0-9_-]+)/', $backup->notes ?? '', $matches);
        $driveId = $matches[1] ?? null;

        if (!$driveId) throw new \Exception("ID Google Drive introuvable dans les notes du backup.");

        $driveService = new \App\Services\Backup\GoogleDriveService($settings);
        
        // Téléchargement temporaire sur le disque du backend pour économiser la RAM
        $localTempPath = storage_path("app/temp_restore_" . $backup->id . "_" . time());
        
        try {
            Log::info("Restauration : Téléchargement depuis Drive vers local ({$localTempPath})");
            $driveService->downloadToFile($driveId, $localTempPath);

            // Envoyer le fichier au VPS via SFTP streaming
            $remoteTempPath = "/tmp/{$backup->name}";
            Log::info("Restauration : Envoi du fichier au VPS ({$remoteTempPath})");
            $this->ssh->uploadFile($remoteTempPath, $localTempPath);

            return $remoteTempPath;
        } finally {
            // Nettoyer le fichier temporaire local quoi qu'il arrive
            if (isset($localTempPath) && file_exists($localTempPath)) {
                @unlink($localTempPath);
            }
        }
    }

    /**
     * Exécute la restauration d'une base de données
     */
    protected function restoreDatabase(Backup $backup, string $restorePath)
    {
        $db = $backup->database;
        if (!$db) throw new \Exception("Base de données associée introuvable.");

        $app = $backup->application;
        $isLegacy = $app->server->infrastructure_type === 'legacy';
        $type = $db->type;
        $dbUser = escapeshellarg($db->db_user);
        $dbName = escapeshellarg($db->db_name);
        $dbPass = $db->db_password;

        if ($type === 'mysql' || $type === 'mariadb') {
             if ($isLegacy) {
                 $restoreCmd = "gunzip -c {$restorePath} | MYSQL_PWD='{$dbPass}' mysql -u {$dbUser} {$dbName}";
             } else {
                 $restoreCmd = "gunzip -c {$restorePath} | docker exec -i -e MYSQL_PWD='{$dbPass}' {$db->uuid} mysql --user={$dbUser} {$dbName} 2>/dev/null";
             }
        } else {
             if ($isLegacy) {
                 $restoreCmd = "gunzip -c {$restorePath} | PGPASSWORD='{$dbPass}' psql -U {$dbUser} {$dbName}";
             } else {
                 $restoreCmd = "gunzip -c {$restorePath} | docker exec -i -e PGPASSWORD='{$dbPass}' {$db->uuid} psql -U {$dbUser} {$dbName}";
             }
        }

        // Masquer le mot de passe dans les logs
        $maskedCmd = preg_replace('/(PASSWORD|MYSQL_PWD)=[\'"].*?[\'"]/', '$1=\'********\'', $restoreCmd);
        Log::info("Exécution de la commande de restauration DB: {$maskedCmd}");
        $this->ssh->exec($restoreCmd);
    }

    /**
     * Exécute la restauration d'un volume
     */
    protected function restoreVolume(Backup $backup, string $restorePath)
    {
        $app = $backup->application;
        $isLegacy = $app->server->infrastructure_type === 'legacy';
        
        // On essaie de retrouver le volume via le mount_path stocké dans les notes
        preg_match('/Volume: (.+)/', $backup->notes ?? '', $matches);
        $mountPath = $matches[1] ?? null;

        if (!$mountPath) throw new \Exception("Chemin du volume introuvable dans les notes.");

        $volume = \App\Models\LocalPersistentVolume::where('resource_id', $app->id)
            ->where('resource_type', Application::class)
            ->where('mount_path', $mountPath)
            ->first();

        if (!$volume) throw new \Exception("Volume persistant introuvable pour le chemin {$mountPath}.");

        $hostPath = $volume->host_path;

        if ($isLegacy) {
            if ($app->legacy_deployment_strategy === 'professional') {
                $hostPath = rtrim($app->target_path, '/') . "/shared/" . ltrim($mountPath, '/');
            } else {
                if ($hostPath && !str_starts_with($hostPath, '/')) {
                    $hostPath = rtrim($app->target_path, '/') . '/' . ltrim($hostPath, '/');
                } elseif (!$hostPath) {
                    $hostPath = rtrim($app->target_path, '/') . '/' . ltrim($mountPath, '/');
                }
            }
        } else {
            if (!$hostPath) {
                $appSlug = preg_replace('/[^a-z0-9\-]/', '-', strtolower($app->name));
                $hostPath = "/var/lib/docker/volumes/{$appSlug}_{$volume->name}/_data";
            }
        }

        // Restauration
        $tarCmd = "tar -xzf {$restorePath} -C {$hostPath} .";
        Log::info("Exécution de la commande de restauration Volume: {$tarCmd}");
        $this->ssh->exec($tarCmd);
    }

    /**
     * Liste les backups d'une application.
     */
    public function getBackups(Application $app)
    {
        return Backup::where('application_id', $app->id)
            ->latest()
            ->get();
    }
}
