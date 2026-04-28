<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Backup;
use Illuminate\Http\Request;

class BackupController extends Controller
{
    /**
     * Liste toutes les sauvegardes de l'utilisateur (toutes apps confondues)
     */
    public function index(Request $request)
    {
        $user = $request->user();

        // On récupère les sauvegardes des applications appartenant à l'utilisateur
        return response()->json(
            Backup::whereHas('application', function($query) use ($user) {
                $query->where('user_id', $user->id);
            })
            ->with(['application:id,name', 'application.server:id,name'])
            ->latest()
            ->get()
            ->map(function($backup) {
                return [
                    'id' => $backup->id,
                    'name' => $backup->name,
                    'type' => $backup->type,
                    'status' => $backup->status,
                    'size' => $backup->size,
                    'created_at' => $backup->created_at,
                    'application' => $backup->application,
                    'server' => $backup->application->server ?? null,
                    'path' => $backup->path,
                ];
            })
        );
    }
}
