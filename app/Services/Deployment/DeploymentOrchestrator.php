<?php

namespace App\Services\Deployment;

use App\Enums\DeploymentStatus;
use App\Enums\LogType;
use App\Models\Application;
use App\Models\Deployment;
use App\Models\Server;
use Exception;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class DeploymentOrchestrator
{
    public function __construct(
        protected SSHService $ssh,
        protected DockerService $docker,
        protected GitService $git,
        protected NixpacksService $nixpacks,
        protected BlueprintService $blueprint,
        protected LogStreamer $streamer,
        protected CronService $cron
    ) {
    }

    /**
     * Point d'entrée principal pour le déploiement d'une application.
     * Gère tout le cycle de vie de manière asynchrone (Job).
     */
    public function deploy(Application $app, Server $server, Deployment $deployment): void
    {
        Log::info("[Deploy] Starting deployment for App: {$app->name} (ID: {$app->id}) on Server: {$server->ip}");

        // STEP 0: INITIAL FEEDBACK
        $this->streamer->log($deployment, " Deployment process started for {$app->name}...", LogType::INFO);
        $this->streamer->flush($deployment); // Force immediate feedback

        // Verrouillage de l'application
        Log::info("[Deploy] Locking application...");
        $app->update(['is_deploying' => true]);

        try {
            // STEP 1: PREPARING
            Log::info("[Deploy] Step 1: Preparing status...");
            $this->updateStatus($app, $deployment, DeploymentStatus::PREPARING);

            if (!$deployment->deployment_uuid) {
                $deployment->update(['deployment_uuid' => (string) Str::uuid()]);
            }

            Log::info("[Deploy] Step 1: Connecting to SSH...");
            $this->streamer->log($deployment, "Connecting to VPS {$server->ip}...", LogType::INFO);

            $this->ssh->connect($server);

            // S'assurer que les outils de base sont là
            $this->docker->ensureInstalled($server, $deployment);
            $this->nixpacks->ensureInstalled($server, $deployment);
            $this->ensureTraefik($server, $deployment);

            $appSlug = $this->blueprint->getSlug($app);
            $appPath = "/var/www/vpsly/apps/" . $appSlug;
            $this->ssh->exec("mkdir -p \"{$appPath}\"");

            $this->streamer->log($deployment, "Connected. Workspace ready at {$appPath}", LogType::SUCCESS);

            // ANALYSE : Variables problématiques (Copy of Coolify)
            $userVars = $app->environmentVariables()->pluck('value', 'key')->toArray();
            $warnings = \App\Traits\EnvironmentVariableAnalyzer::analyzeBuildVariables($userVars);
            foreach ($warnings as $warning) {
                $this->streamer->log($deployment, "⚠️ BUILD WARNING: {$warning['variable']}={$warning['value']}", LogType::INFO);
                $this->streamer->log($deployment, "   Issue: {$warning['issue']}", LogType::DEBUG);
                $this->streamer->log($deployment, "   Recommendation: {$warning['recommendation']}", LogType::DEBUG);
            }

            // STEP 2: CLONING
            $this->updateStatus($app, $deployment, DeploymentStatus::CLONING);
            $this->streamer->log($deployment, "----------------------------------------", LogType::INFO);
            $this->streamer->log($deployment, "Synchronizing code from {$app->repo_url}...", LogType::INFO);

            $this->git->sync($app->repo_url, $app->branch, $appPath, $app->user->github_token);

            // VERSIONING : On récupère le commit (Copy of Coolify)
            $commitInfo = $this->git->getLatestCommit($appPath);
            $deployment->update([
                'commit' => $commitInfo['hash'],
                'commit_message' => $commitInfo['message']
            ]);

            $this->streamer->log($deployment, "Code synchronized. Commit: " . substr($commitInfo['hash'], 0, 7) . " - " . $commitInfo['message'], LogType::SUCCESS);

            // NETTOYAGE : Invalidation du cache et des fichiers de détection parasites
            $this->ssh->exec("rm -rf \"{$appPath}/.nixpacks\" \"{$appPath}/.node-version\" \"{$appPath}/.npmrc\" \"{$appPath}/.pnpm-lock.yaml\"");

            // ANALYSE : Résolution de la version Node
            $nodeVersion = $this->resolveNodeVersion($appPath, $deployment);
            $this->ssh->exec("echo \"{$nodeVersion}\" > \"{$appPath}/.node-version\"");

            // STEP 3: BUILDING (Nixpacks Engine)
            $this->updateStatus($app, $deployment, DeploymentStatus::BUILDING);
            $this->streamer->log($deployment, "----------------------------------------", LogType::INFO);
            $this->streamer->log($deployment, "Starting Nixpacks build process...", LogType::INFO);

            // ANALYSE : On récupère le plan nixpacks pour identifier la stack
            $nixpacksPlan = $this->nixpacks->getPlan($server, $deployment, $appPath, $nodeVersion);

            // IMAGE TAG : On utilise le SHA pour l'immuabilité (Copy of Coolify)
            $imageTag = substr($commitInfo['hash'], 0, 12);
            $imageName = "vpsly/{$appSlug}:{$imageTag}";

            $this->nixpacks->build($server, $deployment, $appPath, $imageName, $nodeVersion);

            // STEP 4: DEPLOYING (Docker Compose)
            $this->updateStatus($app, $deployment, DeploymentStatus::DEPLOYING);
            $this->streamer->log($deployment, "----------------------------------------", LogType::INFO);
            $this->streamer->log($deployment, "Preparing deployment configurations...", LogType::INFO);

            $this->blueprint->syncConfiguration($app, $imageName, $appPath, $nixpacksPlan);


            // SÉCURISATION finale des permissions .env
            $this->ssh->exec("cd \"{$appPath}\" && chmod 600 .env");

            $this->streamer->log($deployment, "Deploying with Docker Compose...", LogType::INFO);

            // S'assurer que le réseau global vpsly existe (Standard vpsly)
            $this->ssh->exec("docker network create vpsly 2>/dev/null || true");

            try {
                $this->ssh->stream("cd \"{$appPath}\" && docker compose up -d --remove-orphans", function ($line) use ($deployment) {
                    $this->streamer->log($deployment, $line, LogType::DEBUG);
                });

                // AUTO-MIGRATION (Logique intelligente par stack)
                if ($this->blueprint->isPhp($nixpacksPlan)) {
                    $this->streamer->log($deployment, "📦 Detected Laravel/PHP stack. Waiting for container...", LogType::INFO);

                    // Attendre que le container soit vraiment running (Fix OOM/Race condition)
                    $maxWait = 15;
                    $waited = 0;
                    $status = 'starting';

                    while ($waited < $maxWait) {
                        $status = trim($this->ssh->exec("docker inspect --format='{{.State.Status}}' {$appSlug} 2>/dev/null || echo 'missing'"));
                        if ($status === 'running')
                            break;

                        sleep(2);
                        $waited += 2;
                    }

                    if ($status !== 'running') {
                        throw new \App\Exceptions\Deployment\NonRetryableException("Le container {$appSlug} n'a pas démarré à temps (Status: {$status}).");
                    }

                    $this->streamer->log($deployment, "Container is running. Starting migrations...", LogType::INFO);
                    $this->ssh->stream("docker exec {$appSlug} php artisan migrate --force", function ($line) use ($deployment) {
                        $this->streamer->log($deployment, $line, LogType::DEBUG);
                    });
                } elseif ($this->planContains($nixpacksPlan, 'prisma')) {
                    $this->streamer->log($deployment, "📦 Detected Prisma. Running migrations...", LogType::INFO);
                    $this->ssh->stream("docker exec {$appSlug} npx prisma migrate deploy", function ($line) use ($deployment) {
                        $this->streamer->log($deployment, $line, LogType::DEBUG);
                    });
                }

                // FIX PERMISSIONS : Volumes
                $volumes = $app->persistentVolumes()->get();
                if ($volumes->count() > 0) {
                    foreach ($volumes as $vol) {
                        $this->ssh->exec("docker exec {$appSlug} chown -R 33:33 \"{$vol->mount_path}\" 2>/dev/null || true");
                    }
                }
            } catch (Exception $e) {
                throw new \App\Exceptions\Deployment\NonRetryableException("Docker Compose failed: " . $e->getMessage(), 0, $e);
            }


            // STEP 5: VERIFY (Health Check)
            $this->verify($app, $deployment, $appPath, $nixpacksPlan);

            // STEP 6: SYNC CRONS (Automated Sync)
            try {
                $this->streamer->log($deployment, " Synchronizing scheduled tasks...", LogType::INFO);
                $this->cron->sync($app);
                $this->streamer->log($deployment, " Scheduled tasks synchronized.", LogType::INFO);
            } catch (Exception $e) {
                $this->streamer->log($deployment, "⚠️ Cron sync failed: " . $e->getMessage(), LogType::WARNING);
            }

            // FINAL STEP: SUCCESS
            $this->updateStatus($app, $deployment, DeploymentStatus::SUCCESS);
            $this->streamer->log($deployment, "----------------------------------------", LogType::INFO);
            $this->streamer->log($deployment, " Deployment successful!", LogType::SUCCESS);

            // CLEANUP
            $this->cleanup($deployment);

        } catch (\Throwable $e) {
            $msg = "❌ DEPLOYMENT FAILED: " . $e->getMessage();
            $this->streamer->log($deployment, $msg, LogType::ERROR);

            try {
                $this->updateStatus($app, $deployment, DeploymentStatus::FAILED);
            } catch (\Throwable $dbError) {
                // Si la DB est lockée, on ne peut rien faire de plus ici
            }

            throw $e;
        } finally {
            // SÉCURITÉ ULTIME : On s'assure que le loader s'arrête quoi qu'il arrive
            try {
                $app->update(['is_deploying' => false]);

                // On notifie le front via l'event si ce n'est pas déjà fait
                event(new \App\Events\DeploymentStatusUpdatedEvent(
                    $deployment->id,
                    $app->id,
                    $app->status,
                    false,
                    $app->last_deployed_at?->toIso8601String()
                ));
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::error("Failsafe unlock failed: " . $e->getMessage());
            }

            $this->streamer->flush($deployment);
            $this->ssh->disconnect();
        }
    }

    /**
     * Vérifie que l'application répond réellement (Hybrid Check - Copy of Coolify).
     */
    protected function verify(Application $app, Deployment $deployment, string $appPath, array $nixpacksPlan): void
    {
        $appSlug = $this->blueprint->getSlug($app);
        $serverIp = $app->server->ip ?? '127.0.0.1';
        $domain = "{$appSlug}.{$serverIp}.sslip.io";

        $this->streamer->log($deployment, " Verifying application health (Target: https://{$domain})", LogType::INFO);

        $maxAttempts = 30; // Coolify attend souvent assez longtemps pour les gros builds
        $attempt = 0;
        $success = false;

        while ($attempt < $maxAttempts) {
            $attempt++;

            // 1. État Docker (Check Health status du YAML)
            $health = trim($this->ssh->exec("docker inspect --format='{{.State.Health.Status}}' {$appSlug} 2>/dev/null || echo 'starting'"));
            $status = trim($this->ssh->exec("docker inspect --format='{{.State.Status}}' {$appSlug} 2>/dev/null || echo 'missing'"));

            if ($health === 'healthy') {
                $this->streamer->log($deployment, " Container is healthy (Docker Healthcheck Passed)", LogType::SUCCESS);
                $success = true;
                break;
            }

            if ($status !== 'running') {
                $this->streamer->log($deployment, "❌ Container crashed or not running (Status: {$status})", LogType::ERROR);
                break;
            }

            // 2. Fallback Health Check (HTTP)
            $publicCheck = trim($this->ssh->exec("curl -k -s -o /dev/null -w '%{http_code}' https://{$domain} --max-time 2 2>/dev/null || echo '000'"));
            if (in_array($publicCheck, ['200', '301', '302', '304', '401', '405'])) {
                $this->streamer->log($deployment, " App is responsive (HTTP {$publicCheck})", LogType::SUCCESS);
                $success = true;
                break;
            }

            $this->streamer->log($deployment, " Waiting for app to become healthy... ({$attempt}/{$maxAttempts})", LogType::DEBUG);
            sleep(2);
        }

        if (!$success) {
            $logs = $this->ssh->exec("docker logs {$appSlug} --tail 50 2>&1");
            $this->streamer->log($deployment, "📋 Last logs before failure:\n{$logs}", LogType::ERROR);
            throw new \App\Exceptions\Deployment\NonRetryableException("Application failed to become healthy in time.");
        }
    }


    /**
     * Nettoie les images résiduelles.
     */
    protected function cleanup(Deployment $deployment): void
    {
        try {
            $this->ssh->exec("docker image prune -f");
        } catch (Exception $e) {
            Log::warning("Cleanup failed: " . $e->getMessage());
        }
    }

    protected function updateStatus(Application $app, Deployment $deployment, DeploymentStatus $status): void
    {
        $isFinished = ($status === DeploymentStatus::SUCCESS || $status === DeploymentStatus::FAILED);

        $app->update([
            'status' => $status->value,
            'is_deploying' => !$isFinished,
            'last_deployed_at' => now()
        ]);

        $deployment->update([
            'status' => $status->value,
            'finished_at' => $isFinished ? now() : null
        ]);

        event(new \App\Events\DeploymentStatusUpdatedEvent(
            $deployment->id,
            $app->id,
            $status->value,
            !$isFinished,
            $app->last_deployed_at?->toIso8601String()
        ));
    }

    /**
     * Résout la version de Node.js.
     */
    protected function resolveNodeVersion(string $appPath, Deployment $deployment): string
    {
        $fallbackVersion = "22";
        try {
            $json = $this->ssh->exec("cat \"{$appPath}/package.json\" 2>/dev/null || echo 'not'");
            if (trim($json) === 'not')
                return $fallbackVersion;

            $data = json_decode($json, true);
            $enginesNode = data_get($data, 'engines.node');

            if ($enginesNode && preg_match('/(\d+)/', $enginesNode, $matches)) {
                $v = $matches[1];
                return intval($v) < 20 ? $fallbackVersion : $v;
            }
            return $fallbackVersion;
        } catch (\Exception $e) {
            return $fallbackVersion;
        }
    }

    /**
     * Vérifie Traefik.
     */
    protected function ensureTraefik(Server $server, Deployment $deployment): void
    {
        $this->streamer->log($deployment, " Infrastructure: Ensuring Traefik v3...", LogType::DEBUG);

        $this->ssh->exec("docker network create vpsly 2>/dev/null || true");

        $check = $this->ssh->exec("docker ps --format '{{.Names}}' | grep '^traefik$' || true");
        if (empty(trim($check))) {
            $this->streamer->log($deployment, "Installing Traefik...", LogType::INFO);
            $command = implode(' ', [
                'docker run -d --name traefik --restart always',
                '--network vpsly',
                '-p 80:80 -p 443:443',
                '-v /var/run/docker.sock:/var/run/docker.sock:ro',
                'traefik:v3.6',
                '--api.insecure=true',
                '--providers.docker=true',
                '--providers.docker.exposedbydefault=false',
                '--providers.docker.network=vpsly',
                '--entrypoints.web.address=:80',
                '--entrypoints.web.http.redirections.entryPoint.to=websecure',
                '--entrypoints.web.http.redirections.entryPoint.scheme=https',
                '--entrypoints.websecure.address=:443'
            ]);
            $this->ssh->exec($command);
        }
    }

    /**
     * Helper pour scanner le plan Nixpacks.
     */
    protected function planContains(array $plan, string $keyword): bool
    {
        $jsonStr = strtolower(json_encode($plan));
        return str_contains($jsonStr, $keyword);
    }
}

