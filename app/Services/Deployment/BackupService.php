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
    public function createDatabaseBackup(Application $app, StandalonePostgresql $db): Backup
    {
        $this->ssh->connect($app->server);

        $backupId = Str::random(8);
        $safeAppName = preg_replace('/[^a-z0-9\-_]/i', '_', $app->name);
        $safeDbName = preg_replace('/[^a-z0-9\-_]/i', '_', $db->name);
        $filename = "backup_{$safeAppName}_{$safeDbName}_" . now()->format('Y-m-d_His') . "_{$backupId}.sql";
        $backupDir = "/var/www/vpsly/backups/{$app->id}";
        $backupPath = "{$backupDir}/{$filename}";

        // Créer le dossier de backup s'il n'existe pas
        $this->ssh->exec("mkdir -p {$backupDir}");

        $backup = Backup::create([
            'application_id' => $app->id,
            'database_id' => $db->id,
            'name' => $filename,
            'type' => 'db',
            'status' => 'pending',
            'path' => $backupPath,
        ]);

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

            return $backup;

        } catch (\Exception $e) {
            Log::error("Backup failed for app {$app->name}: " . $e->getMessage());
            $backup->update([
                'status' => 'failed',
                'notes' => $e->getMessage()
            ]);
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
