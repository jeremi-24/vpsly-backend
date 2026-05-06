<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\Backup;
use App\Jobs\CreateBackupJob;
use Illuminate\Http\Request;

class ApplicationBackupController extends Controller
{
    public function index(Application $application)
    {
        $this->authorize('view', $application);
        return response()->json(
            Backup::where('application_id', $application->id)
                ->latest()
                ->get()
        );
    }

    public function store(Request $request, Application $application)
    {
        $this->authorize('update', $application);
        $request->validate([
            'database_id' => 'nullable|exists:standalone_databases,id',
            'volume_id' => 'nullable|exists:local_persistent_volumes,id',
        ]);

        // Pré-création de l'enregistrement pour feedback instantané
        $backup = \App\Models\Backup::create([
            'application_id' => $application->id,
            'database_id' => $request->database_id,
            'name' => 'Initialisation...',
            'type' => $request->database_id ? 'db' : 'volume',
            'status' => 'pending',
            'path' => '',
        ]);

        CreateBackupJob::dispatch($application->id, $request->database_id, $request->volume_id, $backup->id);

        return response()->json($backup);
    }

    public function destroy(Application $application, $backupId)
    {
        $this->authorize('update', $application);
        $backup = Backup::where('application_id', $application->id)->findOrFail($backupId);

        // 1. Suppression sur le VPS (si présent)
        if ($backup->path) {
            try {
                $ssh = app(\App\Services\Deployment\SSHService::class);
                $ssh->connect($application->server);
                $ssh->exec("rm {$backup->path}");
                $ssh->disconnect();
            } catch (\Exception $e) {
                \Illuminate\Support\Facades\Log::warning("Impossible de supprimer le fichier local backup {$backup->id} : " . $e->getMessage());
            }
        }

        // 2. Suppression sur Google Drive (si présent)
        $notes = $backup->notes ?? '';
        if (str_contains($notes, 'Google Drive ID:')) {
            preg_match('/Google Drive ID: ([a-zA-Z0-9_-]+)/', $notes, $matches);
            $driveId = $matches[1] ?? null;

            if ($driveId) {
                try {
                    $user = $application->team->owner; // Correction : On utilise le propriétaire de la team
                    $settings = $user->backupSettings()->first();
                    if ($settings) {
                        $driveService = new \App\Services\Backup\GoogleDriveService($settings);
                        $driveService->deleteFile($driveId);
                    }
                } catch (\Exception $e) {
                    \Illuminate\Support\Facades\Log::warning("Impossible de supprimer le fichier Drive pour backup {$backup->id} : " . $e->getMessage());
                }
            }
        }

        $backup->delete();
        return response()->json(['message' => 'Sauvegarde supprimée.']);
    }

    public function restore(Application $application, $backupId)
    {
        $this->authorize('update', $application);
        $backup = Backup::where('application_id', $application->id)->findOrFail($backupId);

        // On ne peut pas restaurer si une restauration est déjà en cours
        if ($backup->status === 'restoring') {
            return response()->json(['message' => 'Une restauration est déjà en cours.'], 422);
        }

        // Lancer le Job de restauration
        \App\Jobs\RestoreBackupJob::dispatch($backup->id);

        return response()->json([
            'message' => 'La restauration a été lancée en arrière-plan. Vous serez notifié du succès ou de l\'échec.'
        ]);
    }

    public function download(Application $application, $backupId)
    {
        $this->authorize('update', $application);
        $backup = Backup::where('application_id', $application->id)->findOrFail($backupId);

        if ($backup->status !== 'success') {
            abort(404, "Backup non disponible ou en échec.");
        }

        // Cas 1 : Le fichier est sur le VPS
        if ($backup->path) {
            $ssh = app(\App\Services\Deployment\SSHService::class);
            $ssh->connect($application->server);

            try {
                $localTemp = storage_path("app/temp_dl_" . $backup->id . "_" . time());
                $ssh->downloadToFile($backup->path, $localTemp);
                $ssh->disconnect();

                return response()->download($localTemp, $backup->name)->deleteFileAfterSend(true);
            } catch (\Exception $e) {
                if (isset($localTemp) && file_exists($localTemp)) {
                    @unlink($localTemp);
                }
                $ssh->disconnect();
                Log::error("Erreur téléchargement VPS pour backup {$backup->id} : " . $e->getMessage());
                abort(500, "Erreur lors du téléchargement depuis le serveur.");
            }
        }

        // Cas 2 : Le fichier est uniquement sur Google Drive
        $notes = $backup->notes ?? '';
        if (str_contains($notes, 'Google Drive ID:')) {
            preg_match('/Google Drive ID: ([a-zA-Z0-9_-]+)/', $notes, $matches);
            $driveId = $matches[1] ?? null;

            if ($driveId) {
                $user = $application->user;
                $settings = $user->backupSettings()->first();
                
                if ($settings) {
                    try {
                        $driveService = new \App\Services\Backup\GoogleDriveService($settings);
                        
                        return response()->stream(function() use ($driveService, $driveId) {
                            $stream = $driveService->getDownloadStream($driveId);
                            while (!$stream->eof()) {
                                echo $stream->read(1024 * 1024); // 1MB chunks
                            }
                        }, 200, [
                            'Content-Type' => 'application/gzip',
                            'Content-Disposition' => 'attachment; filename="' . $backup->name . '"',
                        ]);
                    } catch (\Exception $e) {
                        Log::error("Erreur téléchargement Drive pour backup {$backup->id} : " . $e->getMessage());
                        abort(500, "Erreur lors du téléchargement depuis Google Drive.");
                    }
                }
            }
        }

        abort(404, "Fichier de sauvegarde introuvable (local ou cloud).");
    }
}
