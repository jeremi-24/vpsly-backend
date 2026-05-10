<?php

namespace App\Jobs;

use App\Services\GitHubService;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class DeleteGitHubWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 2;
    public $timeout = 30;

    public function __construct(
        public int $userId,
        public string $repoUrl,
        public int $hookId
    ) {}

    public function handle(GitHubService $github): void
    {
        $user = User::find($this->userId);
        if (!$user || !$user->github_token) return;

        try {
            $urlPath = parse_url($this->repoUrl, PHP_URL_PATH);
            $parts = explode('/', trim($urlPath, '/'));
            if (count($parts) >= 2) {
                $owner = $parts[0];
                $repo = str_replace('.git', '', $parts[1]);
                $github->deleteWebhook($user, $owner, $repo, $this->hookId);
                Log::info("[GitHubCleanup] Webhook {$this->hookId} deleted for {$repo}");
            }
        } catch (\Exception $e) {
            Log::warning("[GitHubCleanup] Failed to delete webhook : " . $e->getMessage());
        }
    }
}
