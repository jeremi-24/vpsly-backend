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
        // TODO: Supprimer le fichier sur le VPS via SSH
        $backup->delete();
        return response()->json(['message' => 'Entrée de sauvegarde supprimée.']);
    }

    public function download($appId, $backupId)
    {
        $backup = Backup::where('application_id', $appId)->findOrFail($backupId);
        $application = Application::with('server')->findOrFail($appId);

        if ($backup->status !== 'success' || !$backup->path) {
            abort(404, "Backup non disponible ou en échec.");
        }

        $ssh = app(\App\Services\Deployment\SSHService::class);
        $ssh->connect($application->server);

        try {
            $content = $ssh->download($backup->path);

            return response($content)
                ->header('Content-Type', 'application/gzip')
                ->header('Content-Disposition', 'attachment; filename="' . $backup->name . '"');

        } finally {
            $ssh->disconnect();
        }
    }
}
