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
        return response()->json(
            Application::with(['server', 'databases', 'persistentVolumes'])
                ->latest()
                ->get()
        );
    }

    public function store(Request $request)
    {
        $request->validate([
            'server_id' => 'required|exists:servers,id',
            'name' => 'required|string|unique:applications,name',
            'deployment_mode' => 'required|string|in:docker,legacy_existing',
            'repo_url' => 'required_if:deployment_mode,docker|nullable|url',
            'branch' => 'nullable|string',
            'domain' => 'nullable|string',
            'preset' => 'nullable|string',
            'target_path' => 'required_if:deployment_mode,legacy_existing|nullable|string',
            'deploy_script' => 'required_if:deployment_mode,legacy_existing|nullable|string',
            'log_command' => 'nullable|string',
        ]);
        $user = auth()->user();

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

    public function update(Request $request, $id)
    {
        $app = Application::findOrFail($id);

        $request->validate([
            'server_id' => 'required|exists:servers,id',
            'name' => 'required|string|unique:applications,name,' . $app->id,
            'deployment_mode' => 'required|string|in:docker,legacy_existing',
            'repo_url' => 'required_if:deployment_mode,docker|nullable|url',
            'branch' => 'nullable|string',
            'domain' => 'nullable|string',
            'preset' => 'nullable|string',
            'target_path' => 'required_if:deployment_mode,legacy_existing|nullable|string',
            'deploy_script' => 'required_if:deployment_mode,legacy_existing|nullable|string',
            'log_command' => 'nullable|string',
        ]);

        $app->update([
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
            'application' => $app->load('server')
        ]);
    }

    public function show($id)
    {
        $user = auth()->user();
        $app = Application::with([
                'server',
                'deployments' => fn($q) => $q->latest()->limit(5),
                'environmentVariables',
                'databases',
                'persistentVolumes',
            ])
            ->findOrFail($id);

        return response()->json($app);
    }

    public function destroy($id, \App\Services\GitHubService $github)
    {
        $user = auth()->user();
        $app = Application::findOrFail($id);

        // 1. Dispatch du nettoyage serveur (Avant de supprimer le modèle !)
        \App\Jobs\DeleteApplicationJob::dispatch((int)$app->server_id, (string)$app->slug);

        // 2. Nettoyage Webhook GitHub
        if ($app->github_hook_id && $user->github_token) {
            try {
                $urlPath = parse_url($app->repo_url, PHP_URL_PATH);
                $parts = explode('/', trim($urlPath, '/'));
                if (count($parts) >= 2) {
                    $owner = $parts[0];
                    $repo = str_replace('.git', '', $parts[1]);
                    $github->deleteWebhook($user, $owner, $repo, (int)$app->github_hook_id);
                }
            } catch (\Exception $e) {
                \Illuminate\Support\Facades\Log::warning("Échec suppression webhook : " . $e->getMessage());
            }
        }

        $app->delete();

        return response()->json(['message' => 'Application supprimée avec succès. Le nettoyage du serveur est en cours en arrière-plan.']);
    }

    public function deployments($id)
    {
        $user = auth()->user();
        $app = Application::findOrFail($id);
        
        return response()->json(
            $app->deployments()->latest()->paginate(20)
        );
    }
}

