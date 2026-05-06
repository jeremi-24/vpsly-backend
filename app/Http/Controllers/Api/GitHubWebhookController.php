<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

use App\Models\Application;
use App\Jobs\DeployApplicationJob;
use Illuminate\Support\Facades\Log;

class GitHubWebhookController extends Controller
{
    public function handle(Request $request, $uuid = null)
    {
        try {
            Log::info("GitHub Webhook received", [
                'uuid' => $uuid,
                'event' => $request->header('X-GitHub-Event'),
            ]);

            // 1. Identification de l'application
            if ($uuid) {
                $app = Application::where('uuid', $uuid)->first();
            } else {
                // Fallback Legacy : Identification par repo/branch
                $repoUrl = $request->input('repository.html_url');
                $branch = str_replace('refs/heads/', '', $request->input('ref', ''));
                $app = Application::where('repo_url', 'like', "%{$repoUrl}%")
                    ->where('branch', $branch)
                    ->first();
            }

            if (!$app) {
                Log::info("GitHub Webhook: No matching application found");
                return response()->json(['message' => 'No matching application found'], 200);
            }

            // 2. Validation de la signature GitHub (Utilise le secret spécifique à l'app ou global)
            $signature = $request->header('X-Hub-Signature-256');
            $secret = $app->webhook_secret ?? config('app.webhook_secret', 'vpsly_secret_key');
            
            $payload = $request->getContent();
            $expectedSignature = 'sha256=' . hash_hmac('sha256', $payload, $secret);

            if (!$signature || !hash_equals($signature, $expectedSignature)) {
                Log::warning("GitHub Webhook: Invalid signature for app {$app->name}");
                return response()->json(['message' => 'Invalid signature'], 403);
            }

            // 3. Vérification de l'événement
            $event = $request->header('X-GitHub-Event');
            if ($event === 'ping') {
                return response()->json(['message' => 'pong']);
            }

            if ($event !== 'push') {
                return response()->json(['message' => 'Ignoring event'], 200);
            }

            // 4. Vérification du plan (Auto-push réservé au plan PRO)
            $team = $app->team;
            if ($team && !$team->hasFeature('github_webhooks')) {
                Log::info("GitHub Webhook: Auto-push ignored for app {$app->name} (Plan {$team->plan} does not support it).");
                return response()->json(['message' => 'Plan restriction'], 200);
            }

            // 5. Déclenchement du déploiement
            if (!$app->is_deploying) {
                Log::info("GitHub Webhook: Triggering deployment for app {$app->name}");
                
                $app->update(['is_deploying' => true]);

                $deployment = \App\Models\Deployment::create([
                    'application_id' => $app->id,
                    'status' => \App\Enums\DeploymentStatus::PENDING->value,
                ]);

                // Broadcast immédiat pour le frontend (loader)
                event(new \App\Events\DeploymentStatusUpdatedEvent(
                    $deployment->id,
                    $app->id,
                    \App\Enums\DeploymentStatus::PENDING->value,
                    true
                ));

                DeployApplicationJob::dispatch($deployment->id);
                
                return response()->json(['message' => 'Deployment triggered']);
            } else {
                Log::warning("GitHub Webhook: App {$app->name} is already deploying.");
                return response()->json(['message' => 'Already deploying'], 200);
            }

        } catch (\Exception $e) {
            Log::error("GitHub Webhook Error: " . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
}
