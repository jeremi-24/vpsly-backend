<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\StandalonePostgresql;
use App\Models\Server;
use App\Services\Deployment\DatabaseProvisioner;
use App\Jobs\DeployDatabaseJob;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class DatabaseController extends Controller
{
    public function index(Request $request)
    {
        return StandalonePostgresql::with('server')->latest()->get()->each->append(['internal_db_url', 'external_db_url']);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'server_id' => 'required|exists:servers,id',
            'image' => 'nullable|string',
            'postgres_user' => 'nullable|string',
            'postgres_password' => 'nullable|string',
            'postgres_db' => 'nullable|string',
        ]);

        $database = StandalonePostgresql::create([
            'uuid' => (string) Str::uuid(),
            'name' => $validated['name'],
            'server_id' => $validated['server_id'],
            'image' => $validated['image'] ?? 'postgres:15-alpine',
            'postgres_user' => $validated['postgres_user'] ?? 'postgres',
            'postgres_password' => $validated['postgres_password'] ?? Str::random(16),
            'postgres_db' => $validated['postgres_db'] ?? 'postgres',
            'status' => 'creating',
        ]);

        return response()->json($database, 201);
    }

    public function show(StandalonePostgresql $database)
    {
        return $database->load(['server', 'persistentStorages'])
            ->append(['internal_db_url', 'external_db_url']);
    }

    /**
     * Lance le déploiement de la base de données (Action manuelle ou automatique).
     */
    public function deploy($id)
    {
        $database = StandalonePostgresql::findOrFail($id);
        
        // Mise à jour de l'état avant le dispatch
        $database->update(['status' => 'deploying']);

        DeployDatabaseJob::dispatch($database);

        return response()->json([
            'message' => 'Déploiement ajouté à la file d\'attente',
            'database' => $database
        ]);
    }

    /**
     * Bascule l'accès public de la base de données.
     */
    public function togglePublic(StandalonePostgresql $database)
    {
        $database->is_public = !$database->is_public;
        
        if ($database->is_public && !$database->public_port) {
            // Assignation d'un port public si activé
            $lastPort = StandalonePostgresql::where('server_id', $database->server_id)
                ->whereNotNull('public_port')
                ->max('public_port');
                
            $database->public_port = $lastPort ? $lastPort + 1 : 5432;
        }
        
        $database->save();
        
        // On redéploie pour appliquer le changement
        return $this->deploy($database->id);
    }
}
