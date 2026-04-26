<?php

namespace App\Services\Deployment;

use App\Models\Application;
use App\Models\Backup;
use App\Models\StandalonePostgresql;
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
    public function createDatabaseBackup(Application $app, StandalonePostgresql $db, ?int $backupId = null): Backup
    {
        $this->ssh->connect($app->server);

        $backupId_str = Str::random(8);
        $safeAppName = preg_replace('/[^a-z0-9\-_]/i', '_', $app->name);
        $safeDbName = preg_replace('/[^a-z0-9\-_]/i', '_', $db->name);
        $filename = "backup_{$safeAppName}_{$safeDbName}_" . now()->format('Y-m-d_His') . "_{$backupId_str}.sql";
        $backupDir = "/var/www/vpsly/backups/{$app->id}";
        $backupPath = "{$backupDir}/{$filename}";

        // Créer le dossier de backup s'il n'existe pas
        $this->ssh->exec("mkdir -p {$backupDir}");

        if ($backupId) {
            $backup = Backup::findOrFail($backupId);
            $backup->update([
                'name' => $filename,
                'path' => $backupPath,
                'status' => 'pending'
            ]);
        } else {
            $backup = Backup::create([
                'application_id' => $app->id,
                'database_id' => $db->id,
                'name' => $filename,
                'type' => 'db',
                'status' => 'pending',
                'path' => $backupPath,
            ]);
        }

        try {
            $isMysql = str_contains(strtolower($db->image), 'mysql') || str_contains(strtolower($db->image), 'mariadb');
            
            if ($isMysql) {
                // Approche Sidecar pour MySQL
                // On utilise mysql:8.4 qui contient mysqldump
                // On ajoute --no-tablespaces pour éviter les erreurs de privilèges PROCESS
                $dumpCmd = "bash -c \"docker run --rm --network vpsly " .
                           "mysql:8.4 " .
                           "mysqldump --no-tablespaces -h {$db->uuid} -u {$db->postgres_user} -p'{$db->postgres_password}' {$db->postgres_db} > {$backupPath}\"";
            } else {
                // Approche Sidecar pour Postgres
                $dumpCmd = "bash -c \"docker run --rm --network vpsly " .
                           "-e PGPASSWORD='{$db->postgres_password}' " .
                           "postgres:16 " .
                           "pg_dump -h {$db->uuid} -U {$db->postgres_user} {$db->postgres_db} > {$backupPath}\"";
            }
            
            Log::info("Running backup command: {$dumpCmd}");
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

        $backupId_str = Str::random(8);
        $safeAppName = preg_replace('/[^a-z0-9\-_]/i', '_', $app->name);
        $safeVolName = preg_replace('/[^a-z0-9\-_]/i', '_', $volume->name ?? 'vol');
        $filename = "backup_vol_{$safeAppName}_{$safeVolName}_" . now()->format('Y-m-d_His') . "_{$backupId_str}.tar.gz";
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
            ]);
        } else {
            $backup = Backup::create([
                'application_id' => $app->id,
                'name' => $filename,
                'type' => 'volume',
                'status' => 'pending',
                'path' => $backupPath,
                'notes' => "Volume: {$volume->mount_path}",
            ]);
        }

        try {
            $hostPath = $volume->host_path;
            
            // Si c'est un volume nommé (pas de host_path), on utilise le chemin Docker par défaut avec le préfixe du projet
            if (!$hostPath) {
                $appSlug = preg_replace('/[^a-z0-9\-]/', '-', strtolower($app->name));
                $hostPath = "/var/lib/docker/volumes/{$appSlug}_{$volume->name}/_data";
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
     * Liste les backups d'une application.
     */
    public function getBackups(Application $app)
    {
        return Backup::where('application_id', $app->id)
            ->latest()
            ->get();
    }
}
