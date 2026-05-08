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
            'deployment_mode' => 'required|string|in:docker,legacy_existing',
            'repo_url' => 'required_if:deployment_mode,docker|nullable|url',
            'branch' => 'nullable|string|regex:/^[a-zA-Z0-9\/._-]+$/',
            'domain' => 'nullable|string|regex:/^[a-zA-Z0-9.-]+$/',
            'preset' => 'nullable|string',
            'target_path' => [
                'required_if:deployment_mode,legacy_existing',
                'nullable',
                'string',
                'regex:/^(\/[a-zA-Z0-9._-]+)+$/', // Strict absolute path validation
            ],
            'deploy_script' => 'required_if:deployment_mode,legacy_existing|nullable|string',
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

        if ($server->infrastructure_type === 'clean' && $request->deployment_mode === 'legacy_existing') {
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
        ]);

        return response()->json([
            'message' => 'Configuration terminée. Lancement du déploiement...',
            'application' => $result['application']->load('server'),
            'deployment_id' => $result['deployment']->id
        ], 201);
    }

    public function update(Request $request, Application $application)
    {
        $this->authorize('update', $application);

        $request->validate([
            'server_id' => 'required|exists:servers,id',
            'name' => 'required|string|unique:applications,name,' . $application->id,
            'deployment_mode' => 'required|string|in:docker,legacy_existing',
            'repo_url' => 'required_if:deployment_mode,docker|nullable|url',
            'branch' => 'nullable|string|regex:/^[a-zA-Z0-9\/._-]+$/',
            'domain' => 'nullable|string|regex:/^[a-zA-Z0-9.-]+$/',
            'preset' => 'nullable|string',
            'target_path' => [
                'required_if:deployment_mode,legacy_existing',
                'nullable',
                'string',
                'regex:/^(\/[a-zA-Z0-9._-]+)+$/', // Strict absolute path validation
            ],
            'deploy_script' => 'required_if:deployment_mode,legacy_existing|nullable|string',
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
            'name' => $request->name,
            'repo_url' => $request->repo_url,
            'branch' => $request->branch ?? 'main',
            'server_id' => $request->server_id,
            'domain' => $request->domain,
            'preset' => $request->preset ?? 'generic',
            'target_path' => $request->target_path,
            'deploy_script' => $request->deploy_script,
            'log_command' => $request->log_command,
        ]);

        return response()->json([
            'message' => 'Application mise à jour avec succès.',
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

        // 1. Dispatch du nettoyage serveur (Avant de supprimer le modèle !)
        \App\Jobs\DeleteApplicationJob::dispatch(
            (int)$application->server_id,
            (string)$application->server->infrastructure_type,
            (string)$application->sanitized_name,
            (string)$application->target_path
        );

        // 2. Nettoyage Webhook GitHub
        if ($application->github_hook_id && $user->github_token) {
            try {
                $urlPath = parse_url($application->repo_url, PHP_URL_PATH);
                $parts = explode('/', trim($urlPath, '/'));
                if (count($parts) >= 2) {
                    $owner = $parts[0];
                    $repo = str_replace('.git', '', $parts[1]);
                    $github->deleteWebhook($user, $owner, $repo, (int)$application->github_hook_id);
                }
            } catch (\Exception $e) {
                \Illuminate\Support\Facades\Log::warning("Échec suppression webhook : " . $e->getMessage());
            }
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

