<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

use App\Models\Application;
use App\Jobs\DeployApplicationJob;
use Illuminate\Support\Facades\Log;

class GitHubWebhookController extends Controller
{
    public function handle(Request $request)
    {
        // 1. Validation de la signature GitHub
        $signature = $request->header('X-Hub-Signature-256');
        $secret = config('app.webhook_secret', 'vpsly_secret_key');
        
        $payload = $request->getContent();
        $expectedSignature = 'sha256=' . hash_hmac('sha256', $payload, $secret);

        if (!$signature || !hash_equals($signature, $expectedSignature)) {
            Log::warning('GitHub Webhook: Invalid signature');
            return response()->json(['message' => 'Invalid signature'], 403);
        }

        // 2. Vérification de l'événement
        $event = $request->header('X-GitHub-Event');
        if ($event === 'ping') {
            return response()->json(['message' => 'pong']);
        }

        if ($event !== 'push') {
            return response()->json(['message' => 'Ignoring event'], 200);
        }

        // 3. Identification de l'application
        $repoUrl = $request->input('repository.html_url');
        $branch = str_replace('refs/heads/', '', $request->input('ref'));

        $applications = Application::where('repo_url', 'like', "%{$repoUrl}%")
            ->where('branch', $branch)
            ->get();

        if ($applications->isEmpty()) {
            return response()->json(['message' => 'No matching application found'], 200);
        }

        foreach ($applications as $app) {
            Log::info("GitHub Webhook: Triggering deployment for app {$app->name}");
            // On s'assure de ne pas relancer un déploiement si un est déjà en cours ?
            // Le job gère déjà ça normalement ou on peut ajouter un check.
            DeployApplicationJob::dispatch($app);
        }

        return response()->json(['message' => 'Deployments triggered']);
    }
}
