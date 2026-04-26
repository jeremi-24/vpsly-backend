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
        try {
            Log::info("GitHub Webhook received", [
                'event' => $request->header('X-GitHub-Event'),
                'signature' => $request->header('X-Hub-Signature-256')
            ]);

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
                Log::info("GitHub Webhook: No matching application found for {$repoUrl} on branch {$branch}");
                return response()->json(['message' => 'No matching application found'], 200);
            }

            foreach ($applications as $app) {
                Log::info("GitHub Webhook: Triggering deployment for app {$app->name}");
                
                if (!$app->is_deploying) {
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
                } else {
                    Log::warning("GitHub Webhook: App {$app->name} is already deploying.");
                }
            }

            return response()->json(['message' => 'Deployments triggered']);
        } catch (\Exception $e) {
            Log::error("GitHub Webhook Error: " . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
}
