<?php

namespace App\Http\Controllers;

use App\Models\Application;
use App\Models\User;
use App\Models\Deployment;
use App\Jobs\DeployApplicationJob;
use Illuminate\Http\Request;

class ApplicationController extends Controller
{
    public function index()
    {
        // La Global Scope TeamScope filtre déjà par team_id
        return response()->json(
            Application::with(['server', 'databases', 'persistentVolumes'])
                ->latest()
                ->get()
        );
    }

    public function store(Request $request)
    {
        $this->authorize('create', Application::class);

        $request->validate([
            'server_id' => 'required|exists:servers,id',
            'name' => 'required|string|unique:applications,name',
            'deployment_mode' => 'required|string|in:docker,legacy_existing,legacy_new',
            'repo_url' => 'required_if:deployment_mode,docker|required_if:deployment_mode,legacy_new|nullable|url',
            'branch' => 'nullable|string|regex:/^[a-zA-Z0-9\/._-]+$/',
            'domain' => 'nullable|string|regex:/^[a-zA-Z0-9.-]+$/',
            'preset' => 'nullable|string',
            'target_path' => [
                'required_if:deployment_mode,legacy_existing',
                'required_if:deployment_mode,legacy_new',
                'nullable',
                'string',
                'regex:/^(\/[a-zA-Z0-9._-]+)+$/', // Strict absolute path validation
            ],
            'deploy_script' => 'required_if:deployment_mode,legacy_existing|required_if:deployment_mode,legacy_new|nullable|string',
            'log_command' => 'nullable|string',
        ]);

        $user = auth()->user();
        $team = $user->currentTeam;

        // Vérification du domaine personnalisé
        if ($request->domain && $team && !$team->hasFeature('custom_domains')) {
            return response()->json([
                'message' => 'Les domaines personnalisés sont réservés aux plans Solo et Pro.',
                'errors' => ['domain' => ['Veuillez passer au plan Solo pour utiliser un domaine personnalisé.']]
            ], 403);
        }

        // DETERMINISTIC RULE: Check server infrastructure type
        $server = \App\Models\Server::findOrFail($request->server_id);
        if ($server->infrastructure_type === 'legacy' && $request->deployment_mode === 'docker') {
            return response()->json([
                'message' => 'Ce serveur est en mode Legacy. Seul le déploiement natif (Legacy) est autorisé.',
                'errors' => ['deployment_mode' => ['Incompatible avec l\'infrastructure du serveur.']]
            ], 422);
        }

        if ($server->infrastructure_type === 'clean' && in_array($request->deployment_mode, ['legacy_existing', 'legacy_new'])) {
            return response()->json([
                'message' => 'Ce serveur est en mode Clean (Docker). Le mode Legacy n\'est pas supporté.',
                'errors' => ['deployment_mode' => ['Incompatible avec l\'infrastructure du serveur.']]
            ], 422);
        }

        // Empêcher les doublons (même repo et même branche) - Uniquement pour Docker
        if ($request->deployment_mode === 'docker') {
            $existing = Application::where('repo_url', $request->repo_url)
                ->where('branch', $request->branch ?? 'main')
                ->first();

            if ($existing) {
                return response()->json([
                    'message' => 'Une application utilisant ce dépôt et cette branche existe déjà.',
                    'errors' => [
                        'repo_url' => ['Ce dépôt et cette branche sont déjà utilisés par l\'application : ' . $existing->name]
                    ]
                ], 422);
            }
        }

        // Utilisation de notre nouvelle action atomique via le container
        $result = app(\App\Actions\Deployment\CreateAtomicStack::class)->execute([
            'user_id' => $user->id,
            'server_id' => $request->server_id,
            'name' => $request->name,
            'deployment_mode' => $request->deployment_mode,
            'repo_url' => $request->repo_url,
            'branch' => $request->branch ?? 'main',
            'domain' => $request->domain,
            'preset' => $request->preset ?? 'generic',
            'target_path' => $request->target_path,
            'deploy_script' => $request->deploy_script,
            'log_command' => $request->log_command,
        ]);

        $application = $result['application'];

        // IMPORT INITIAL POUR LE MODE LEGACY
        if ($application->deployment_mode === 'legacy_existing') {
            try {
                $imported = app(\App\Services\Deployment\LegacyConfigService::class)->importFromRemote($application);
                \Illuminate\Support\Facades\Log::info("[AppStore] Imported {$imported} variables for Legacy App: {$application->name}");
            } catch (\Exception $e) {
                \Illuminate\Support\Facades\Log::error("[AppStore] Failed initial .env import: " . $e->getMessage());
            }
        }

        return response()->json([
            'message' => 'Configuration terminée. Lancement du déploiement...',
            'application' => $application->load('server'),
            'deployment_id' => $result['deployment']->id
        ], 201);

    }

    public function update(Request $request, Application $application)
    {
        $this->authorize('update', $application);

        $request->validate([
            'server_id' => 'required|exists:servers,id',
            'name' => 'required|string|unique:applications,name,' . $application->id,
            'deployment_mode' => 'required|string|in:docker,legacy_existing,legacy_new',
            'repo_url' => 'required_if:deployment_mode,docker|required_if:deployment_mode,legacy_new|nullable|url',
            'branch' => 'nullable|string|regex:/^[a-zA-Z0-9\/._-]+$/',
            'domain' => 'nullable|string|regex:/^[a-zA-Z0-9.-]+$/',
            'preset' => 'nullable|string',
            'target_path' => [
                'required_if:deployment_mode,legacy_existing',
                'required_if:deployment_mode,legacy_new',
                'nullable',
                'string',
                'regex:/^(\/[a-zA-Z0-9._-]+)+$/', // Strict absolute path validation
            ],
            'deploy_script' => 'required_if:deployment_mode,legacy_existing|required_if:deployment_mode,legacy_new|nullable|string',
            'log_command' => 'nullable|string',
        ]);

        $team = auth()->user()->currentTeam;

        // Vérification du domaine personnalisé
        if ($request->domain && $team && !$team->hasFeature('custom_domains')) {
            return response()->json([
                'message' => 'Les domaines personnalisés sont réservés aux plans Solo et Pro.',
                'errors' => ['domain' => ['Veuillez passer au plan Solo pour utiliser un domaine personnalisé.']]
            ], 403);
        }

        $application->update([
            'name' => $request->name ?? $application->name,
            'repo_url' => $request->has('repo_url') ? $request->repo_url : $application->repo_url,
            'branch' => $request->branch ?? $application->branch ?? 'main',
            'server_id' => $request->server_id ?? $application->server_id,
            'domain' => $request->has('domain') ? $request->domain : $application->domain,
            'deployment_mode' => $request->deployment_mode ?? $application->deployment_mode,
            'preset' => $request->preset ?? $application->preset ?? 'generic',
            'target_path' => $request->has('target_path') ? $request->target_path : $application->target_path,
            'deploy_script' => $request->has('deploy_script') ? $request->deploy_script : $application->deploy_script,
            'log_command' => $request->has('log_command') ? $request->log_command : $application->log_command,
        ]);

        // Déclencher un redéploiement automatique après modification
        try {
            // 1. Marquer l'application en cours de déploiement
            $application->update(['is_deploying' => true]);

            // 2. Créer l'enregistrement de déploiement
            $deployment = \App\Models\Deployment::create([
                'application_id' => $application->id,
                'status' => 'pending',
                'started_at' => now(),
            ]);

            // 3. Lancer le Job avec l'ID du déploiement (int)
            \App\Jobs\DeployApplicationJob::dispatch($deployment->id);
            
            $message = "Application mise à jour et redéploiement lancé.";
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error("Failed to auto-deploy after update: " . $e->getMessage());
            $message = "Application mise à jour, mais le redéploiement n'a pas pu être lancé.";
        }

        return response()->json([
            'message' => $message,
            'application' => $application->load('server')
        ]);
    }

    public function show(Application $application)
    {
        $this->authorize('view', $application);

        return response()->json($application->load([
            'server',
            'deployments' => fn($q) => $q->latest()->limit(5),
            'environmentVariables',
            'databases',
            'persistentVolumes',
        ]));
    }

    public function destroy(Application $application, \App\Services\GitHubService $github)
    {
        $this->authorize('delete', $application);

        $user = auth()->user();

        // 1. Nettoyage des Bases de Données associées
        foreach ($application->databases as $db) {
            \App\Jobs\DeleteDatabaseJob::dispatch(
                (int)$application->server_id,
                (string)$db->uuid,
                (string)$application->server->infrastructure_type,
                (string)$db->type,
                (string)$db->db_name,
                (string)$db->db_user
            );
            $db->delete();
        }

        // 2. Dispatch du nettoyage serveur (Avant de supprimer le modèle !)
        \App\Jobs\DeleteApplicationJob::dispatch(
            (int)$application->server_id,
            (string)$application->server->infrastructure_type,
            (string)$application->sanitized_name,
            (string)$application->target_path,
            (string)$application->domain
        );

        // 3. Nettoyage Webhook GitHub (Asynchrone)
        if ($application->github_hook_id && $user->github_token) {
            \App\Jobs\DeleteGitHubWebhookJob::dispatch(
                (int)$user->id,
                (string)$application->repo_url,
                (int)$application->github_hook_id
            );
        }

        $application->delete();

        return response()->json(['message' => 'Application supprimée avec succès. Le nettoyage du serveur est en cours en arrière-plan.']);
    }

    public function deployments(Application $application)
    {
        $this->authorize('deployments', $application);
        
        return response()->json(
            $application->deployments()->latest()->paginate(20)
        );
    }
}

