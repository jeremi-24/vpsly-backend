<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\StandaloneDatabase;
use App\Models\Server;
use App\Services\Deployment\DatabaseProvisioner;
use App\Jobs\DeployDatabaseJob;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class DatabaseController extends Controller
{
    public function index(Request $request)
    {
        // La Global Scope gère déjà le filtrage
        return StandaloneDatabase::with('server')
            ->latest()
            ->get();
    }

    public function store(Request $request)
    {
        $this->authorize('create', StandaloneDatabase::class);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'server_id' => 'required|exists:servers,id',
            'type' => 'required|string|in:postgres,mysql,mariadb,redis',
            'image' => 'nullable|string',
            'db_user' => 'nullable|string',
            'db_password' => 'nullable|string',
            'db_name' => 'nullable|string',
            'has_adminer' => 'nullable|boolean',
        ]);

        // Vérifier que le serveur est accessible
        $server = Server::findOrFail($validated['server_id']);
        $this->authorize('view', $server);

        $defaultImages = [
            'postgres' => 'postgres:15-alpine',
            'mysql' => 'mysql:8.0',
            'mariadb' => 'mariadb:10.11',
            'redis' => 'redis:7-alpine',
        ];

        $database = StandaloneDatabase::create([
            'uuid' => (string) Str::uuid(),
            'type' => $validated['type'],
            'name' => $validated['name'],
            'server_id' => $server->id,
            'image' => $validated['image'] ?? ($defaultImages[$validated['type']] ?? 'postgres:15-alpine'),
            'db_user' => $validated['db_user'] ?? ($validated['type'] === 'redis' ? null : 'vpsly'),
            'db_password' => $validated['db_password'] ?? Str::random(16),
            'db_name' => $validated['db_name'] ?? ($validated['type'] === 'redis' ? null : 'vpsly'),
            'has_adminer' => $validated['has_adminer'] ?? false,
            'status' => 'creating',
        ]);

        return response()->json($database, 201);
    }

    public function show(StandaloneDatabase $database)
    {
        $this->authorize('view', $database);

        return $database->load(['server', 'persistentStorages']);
    }

    /**
     * Lance le déploiement de la base de données.
     */
    public function deploy(StandaloneDatabase $database)
    {
        $this->authorize('update', $database);
        
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
    public function togglePublic(StandaloneDatabase $database)
    {
        $this->authorize('update', $database);

        $database->is_public = !$database->is_public;
        
        if ($database->is_public && !$database->public_port) {
            $lastPort = StandaloneDatabase::where('server_id', $database->server_id)
                ->whereNotNull('public_port')
                ->max('public_port');
                
            $database->public_port = $lastPort ? $lastPort + 1 : 5432;
        }
        
        $database->save();
        
        return $this->deploy($database);
    }

    public function verifyIntegrity(StandaloneDatabase $database, \App\Services\Deployment\SSHService $ssh)
    {
        $this->authorize('view', $database);
        $volumeName = "db-data-{$database->uuid}";
        
        try {
            $ssh->connect($database->server);
            $check = $ssh->exec("docker volume inspect \"{$volumeName}\" > /dev/null 2>&1 && echo 'exists' || echo 'missing'");
            $ssh->disconnect();

            $exists = trim($check) === 'exists';
            
            return response()->json([
                'is_intact' => $exists,
                'volume_name' => $volumeName,
                'message' => $exists ? 'Volume trouvé et intègre' : 'ATTENTION: Volume introuvable sur le VPS'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'is_intact' => false,
                'message' => 'Impossible de contacter le serveur pour vérification'
            ], 500);
        }
    }

    public function link(Request $request, StandaloneDatabase $database)
    {
        $this->authorize('update', $database);

        $validated = $request->validate([
            'application_id' => 'required|exists:applications,id'
        ]);

        $database->update([
            'application_id' => $validated['application_id']
        ]);

        $app = \App\Models\Application::find($validated['application_id']);
        if ($app) {
             $deployment = $app->deployments()->create([
                 'status' => 'pending',
             ]);
             \App\Jobs\DeployApplicationJob::dispatch($deployment->id);
        }

        return response()->json([
            'message' => 'Lien établi. Mise à jour de l\'application en cours...',
            'database' => $database
        ]);
    }

    public function destroy(StandaloneDatabase $database)
    {
        $this->authorize('delete', $database);

        \App\Jobs\DeleteDatabaseJob::dispatch(
            (int) $database->server_id, 
            (string) $database->uuid
        );

        $database->delete();

        return response()->json([
            'message' => 'L\'instance de base de données a été supprimée. Le nettoyage du serveur est en cours.'
        ]);
    }

    public function stop(StandaloneDatabase $database, \App\Services\Deployment\SSHService $ssh)
    {
        $this->authorize('update', $database);

        try {
            $ssh->connect($database->server);
            $ssh->exec("docker stop {$database->uuid}");
            $ssh->disconnect();

            $database->update(['status' => 'exited']);

            return response()->json([
                'message' => 'Instance arrêtée',
                'database' => $database
            ]);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Erreur lors de l\'arrêt: ' . $e->getMessage()], 500);
        }
    }

    public function start(StandaloneDatabase $database, \App\Services\Deployment\SSHService $ssh)
    {
        $this->authorize('update', $database);

        try {
            $ssh->connect($database->server);
            $ssh->exec("docker start {$database->uuid}");
            $ssh->disconnect();

            $database->update(['status' => 'running']);

            return response()->json([
                'message' => 'Instance démarrée',
                'database' => $database
            ]);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Erreur lors du démarrage: ' . $e->getMessage()], 500);
        }
    }

    public function unlink(StandaloneDatabase $database)
    {
        $this->authorize('update', $database);

        $oldAppId = $database->application_id;

        $database->update([
            'application_id' => null
        ]);

        if ($oldAppId) {
            $app = \App\Models\Application::find($oldAppId);
            if ($app) {
                 $deployment = $app->deployments()->create([
                     'status' => 'pending',
                 ]);
                 \App\Jobs\DeployApplicationJob::dispatch($deployment->id);
            }
        }

        return response()->json([
            'message' => 'Lien rompu. Mise à jour de l\'application en cours...',
            'database' => $database
        ]);
    }
}
