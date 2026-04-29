<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\Backup;
use App\Jobs\CreateBackupJob;
use Illuminate\Http\Request;

class ApplicationBackupController extends Controller
{
    public function index($appId)
    {
        $application = Application::findOrFail($appId);
        return response()->json(
            Backup::where('application_id', $application->id)
                ->latest()
                ->get()
        );
    }

    public function store(Request $request, $appId)
    {
        $application = Application::findOrFail($appId);
        $request->validate([
            'database_id' => 'nullable|exists:standalone_postgresqls,id',
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

    public function destroy($appId, $backupId)
    {
        $backup = Backup::where('application_id', $appId)->findOrFail($backupId);
        $application = Application::with('server')->findOrFail($appId);

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
                    $user = $application->user;
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

    public function download($appId, $backupId)
    {
        $backup = Backup::where('application_id', $appId)->findOrFail($backupId);
        $application = Application::with('server', 'user')->findOrFail($appId);

        if ($backup->status !== 'success') {
            abort(404, "Backup non disponible ou en échec.");
        }

        // Cas 1 : Le fichier est sur le VPS
        if ($backup->path) {
            $ssh = app(\App\Services\Deployment\SSHService::class);
            $ssh->connect($application->server);

            try {
                $content = $ssh->download($backup->path);
                return $this->downloadResponse($content, $backup->name);
            } finally {
                $ssh->disconnect();
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
                        $content = $driveService->getFileContent($driveId);
                        return $this->downloadResponse($content, $backup->name);
                    } catch (\Exception $e) {
                        Log::error("Erreur téléchargement Drive pour backup {$backup->id} : " . $e->getMessage());
                        abort(500, "Erreur lors du téléchargement depuis Google Drive.");
                    }
                }
            }
        }

        abort(404, "Fichier de sauvegarde introuvable (local ou cloud).");
    }

    protected function downloadResponse($content, $filename)
    {
        return response($content)
            ->header('Content-Type', 'application/gzip')
            ->header('Content-Disposition', 'attachment; filename="' . $filename . '"');
    }
}
