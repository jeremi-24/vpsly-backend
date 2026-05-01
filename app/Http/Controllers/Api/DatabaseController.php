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
        return StandaloneDatabase::with('server')
            ->whereHas('server', function ($query) {
                $query->where('user_id', auth()->id());
            })
            ->latest()
            ->get();
    }

    public function store(Request $request)
    {
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

        // Vérifier que le serveur appartient bien à l'utilisateur
        $server = Server::where('user_id', auth()->id())->findOrFail($validated['server_id']);

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
        $this->authorizeOwner($database);

        return $database->load(['server', 'persistentStorages']);
    }

    /**
     * Lance le déploiement de la base de données (Action manuelle ou automatique).
     */
    public function deploy($id)
    {
        $database = StandaloneDatabase::findOrFail($id);
        $this->authorizeOwner($database);
        
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
    public function togglePublic(StandaloneDatabase $database)
    {
        $this->authorizeOwner($database);

        $database->is_public = !$database->is_public;
        
        if ($database->is_public && !$database->public_port) {
            // Assignation d'un port public si activé
            $lastPort = StandaloneDatabase::where('server_id', $database->server_id)
                ->whereNotNull('public_port')
                ->max('public_port');
                
            $database->public_port = $lastPort ? $lastPort + 1 : 5432;
        }
        
        $database->save();
        
        // On redéploie pour appliquer le changement
        return $this->deploy($database->id);
    }
    public function verifyIntegrity($id, \App\Services\Deployment\SSHService $ssh)
    {
        $database = StandaloneDatabase::findOrFail($id);
        $this->authorizeOwner($database);
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
        $this->authorizeOwner($database);

        $validated = $request->validate([
            'application_id' => 'required|exists:applications,id'
        ]);

        $database->update([
            'application_id' => $validated['application_id']
        ]);

        // Déclencher le redéploiement de l'application
        $app = \App\Models\Application::find($validated['application_id']);
        if ($app) {
             // On crée un nouveau déploiement via le contrôleur dédié pour avoir les logs
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

    /**
     * Supprime l'instance de base de données.
     */
    public function destroy(StandaloneDatabase $database)
    {
        $this->authorizeOwner($database);

        // 1. Dispatch du job de nettoyage (Avant suppression du modèle)
        \App\Jobs\DeleteDatabaseJob::dispatch(
            (int) $database->server_id, 
            (string) $database->uuid
        );

        // 2. Suppression de l'entrée en DB
        $database->delete();

        return response()->json([
            'message' => 'L\'instance de base de données a été supprimée. Le nettoyage du serveur est en cours.'
        ]);
    }

    /**
     * Dissocie la base de données de son application.
     */
    public function stop(StandaloneDatabase $database, \App\Services\Deployment\SSHService $ssh)
    {
        $this->authorizeOwner($database);

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
        $this->authorizeOwner($database);

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
        $this->authorizeOwner($database);

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

    /**
     * Vérifie que l'utilisateur est bien le propriétaire du serveur qui héberge la base.
     */
    protected function authorizeOwner(StandaloneDatabase $database)
    {
        if ($database->server->user_id !== auth()->id()) {
            abort(403, 'Accès non autorisé à cette base de données.');
        }
    }
}
