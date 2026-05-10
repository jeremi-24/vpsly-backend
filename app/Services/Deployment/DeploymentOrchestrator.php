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
        protected LegacyConfigService $legacyConfig,
        protected LegacyProvisionerService $provisioner,
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

        // Verrouillage de l'application et init déploiement
        Log::info("[Deploy] Locking application and initializing metadata...");
        $app->update(['is_deploying' => true]);

        $deployment->update([
            'started_at' => now(),
            'branch' => $app->branch ?? 'main',
        ]);

        try {
            // DETERMINISTIC BRANCHING BASED ON SERVER TYPE
            if ($server->infrastructure_type === 'legacy') {
                Log::info("[Deploy] Server is LEGACY. Using native SSH deployment.");
                $this->deployLegacy($app, $server, $deployment);
                return;
            }

            // CLEAN SERVER FLOW (Docker + Traefik)
            $this->deployClean($app, $server, $deployment);

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
     * Flux de déploiement pour serveur CLEAN (Docker + Traefik).
     */
    protected function deployClean(Application $app, Server $server, Deployment $deployment): void
    {
        // STEP 1: PREPARING
        Log::info("[Deploy-Clean] Step 1: Preparing status...");
        $this->updateStatus($app, $deployment, DeploymentStatus::PREPARING);

        if (!$deployment->deployment_uuid) {
            $deployment->update(['deployment_uuid' => (string) Str::uuid()]);
        }

        Log::info("[Deploy-Clean] Step 1: Connecting to SSH...");
        $this->streamer->log($deployment, "Connecting to VPS {$server->ip} (Clean Mode)...", LogType::INFO);

        $this->ssh->connect($server);

        // S'assurer que les outils de base sont là
        $this->docker->ensureInstalled($server, $deployment);
        $this->nixpacks->ensureInstalled($server, $deployment);
        $this->ensureTraefik($server, $deployment);

        $appSlug = $this->blueprint->getSlug($app);
        $appPath = "/var/www/vpsly/apps/" . $appSlug;
        $appPathEscaped = escapeshellarg($appPath);
        $this->ssh->exec("mkdir -p {$appPathEscaped}");

        $this->streamer->log($deployment, "Connected. Workspace ready at {$appPath}", LogType::SUCCESS);

        // ANALYSE : Variables problématiques
        $userVars = $app->environmentVariables()->pluck('value', 'key')->toArray();
        $warnings = \App\Traits\EnvironmentVariableAnalyzer::analyzeBuildVariables($userVars);
        foreach ($warnings as $warning) {
            $this->streamer->log($deployment, "⚠️ BUILD WARNING: {$warning['variable']}={$warning['value']}", LogType::INFO);
        }

        // STEP 2: CLONING
        $this->updateStatus($app, $deployment, DeploymentStatus::CLONING);
        $this->streamer->log($deployment, "----------------------------------------", LogType::INFO);
        $this->streamer->log($deployment, "Synchronizing code from {$app->repo_url}...", LogType::INFO);

        $this->git->sync($app->repo_url, $app->branch, $appPath, $app->user->github_token);

        // VERSIONING : On récupère le commit
        $commitInfo = $this->git->getLatestCommit($appPath);
        $deployment->update([
            'commit' => $commitInfo['hash'],
            'commit_message' => $commitInfo['message']
        ]);

        $this->streamer->log($deployment, "Code synchronized. Commit: " . substr($commitInfo['hash'], 0, 7), LogType::SUCCESS);

        // NETTOYAGE : Invalidation du cache
        $this->ssh->exec("rm -rf {$appPathEscaped}/.nixpacks {$appPathEscaped}/.node-version {$appPathEscaped}/.npmrc {$appPathEscaped}/.pnpm-lock.yaml");

        // ANALYSE : Résolution de la version Node
        $nodeVersion = $this->resolveNodeVersion($appPath, $deployment);
        $nodeVersionEscaped = escapeshellarg($nodeVersion);
        $this->ssh->exec("echo {$nodeVersionEscaped} > {$appPathEscaped}/.node-version");

        // STEP 3: BUILDING (Nixpacks Engine)
        $this->updateStatus($app, $deployment, DeploymentStatus::BUILDING);
        $this->streamer->log($deployment, "----------------------------------------", LogType::INFO);
        $this->streamer->log($deployment, "Starting Nixpacks build process...", LogType::INFO);

        $nixpacksPlan = $this->nixpacks->getPlan($server, $deployment, $appPath, $nodeVersion);
        $imageTag = substr($commitInfo['hash'], 0, 12);
        $imageName = "vpsly/{$appSlug}:{$imageTag}";

        $this->nixpacks->build($server, $deployment, $appPath, $imageName, $nodeVersion);

        // STEP 4: DEPLOYING (Docker Compose)
        $this->updateStatus($app, $deployment, DeploymentStatus::DEPLOYING);
        $this->streamer->log($deployment, "----------------------------------------", LogType::INFO);
        $this->streamer->log($deployment, "Preparing deployment configurations...", LogType::INFO);

        $this->blueprint->syncConfiguration($app, $imageName, $appPath, $nixpacksPlan);

        // SÉCURISATION finale des permissions .env
        $this->ssh->exec("cd {$appPathEscaped} && chmod 600 .env");

        $this->streamer->log($deployment, "Deploying with Docker Compose...", LogType::INFO);

        // S'assurer que le réseau global vpsly existe
        $this->ssh->exec("docker network create vpsly 2>/dev/null || true");

        $this->ssh->stream("cd {$appPathEscaped} && docker compose up -d --remove-orphans", function ($line) use ($deployment) {
            $this->streamer->log($deployment, $line, LogType::DEBUG);
        });

        // AUTO-MIGRATION
        if ($this->blueprint->isPhp($nixpacksPlan)) {
            $appSlugEscaped = escapeshellarg($appSlug);
            $this->ssh->stream("docker exec {$appSlugEscaped} php artisan migrate --force", function ($line) use ($deployment) {
                $this->streamer->log($deployment, $line, LogType::DEBUG);
            });
        }

        // STEP 5: VERIFY (Health Check)
        $this->verify($app, $deployment, $appPath, $nixpacksPlan);

        // STEP 6: SYNC CRONS
        try {
            $this->cron->sync($app);
        } catch (Exception $e) {
            $this->streamer->log($deployment, "⚠️ Cron sync failed", LogType::WARNING);
        }

        // FINAL SUCCESS
        $this->updateStatus($app, $deployment, DeploymentStatus::SUCCESS);
        $this->streamer->log($deployment, "----------------------------------------", LogType::INFO);
        $this->streamer->log($deployment, " Deployment successful!", LogType::SUCCESS);

        $this->cleanup($deployment);
    }


    /**
     * Vérifie que l'application répond réellement (Hybrid Check - Copy of Coolify).
     */
    protected function verify(Application $app, Deployment $deployment, string $appPath, array $nixpacksPlan): void
    {
        $appSlug = $this->blueprint->getSlug($app);
        $serverIp = $app->server->ip ?? '127.0.0.1';
        $domain = $app->domain ?: "{$appSlug}.{$serverIp}.sslip.io";

        $this->streamer->log($deployment, " Verifying application health (Target: https://{$domain})", LogType::INFO);

        $maxAttempts = 30; // Coolify attend souvent assez longtemps pour les gros builds
        $attempt = 0;
        $success = false;

        while ($attempt < $maxAttempts) {
            $attempt++;

            // 1. État Docker (Check Health status du YAML)
            $health = trim($this->ssh->exec("docker inspect --format='{{.State.Health.Status}}' {$appSlug} 2>/dev/null || echo 'starting'"));
            $status = trim($this->ssh->exec("docker inspect -f '{{.State.Status}}' {$appSlug} 2>/dev/null || echo 'not_found'"));

            if ($health === 'healthy') {
                $this->streamer->log($deployment, " Container is healthy (Docker Healthcheck Passed)", LogType::SUCCESS);
                $success = true;
                break;
            }

            if ($status !== 'running') {
                $this->streamer->log($deployment, "❌ Container crashed or not running (Status: {$status})", LogType::ERROR);
                break;
            }

            // 2. Health Check Layer (Internal focus to avoid DNS/SSL issues)
            $hcPath = $app->healthcheck_path ?: '/';
            $hcCodesStr = $app->healthcheck_status_codes ?: '200,301,302,304,401,405';
            $allowedCodes = array_map('intval', explode(',', $hcCodesStr));
            $containerPort = (str_contains($app->image ?: '', 'php') || $app->build_pack === 'nixpacks') ? 80 : 3000;

            // Test 1: Internal Health Check (Directly in container)
            $check = (int) trim($this->ssh->exec("docker exec {$appSlug} curl -k -s -o /dev/null -w '%{http_code}' http://localhost:{$containerPort}{$hcPath} --max-time 3 2>/dev/null || echo '000'"));
            
            if (in_array($check, $allowedCodes)) {
                $this->streamer->log($deployment, "✅ App is healthy (Internal HTTP {$check} on {$hcPath})", LogType::SUCCESS);
                $success = true;
                break;
            }

            // Test 2: Internal Fallback to Root
            if ($hcPath !== '/') {
                $rootCheck = (int) trim($this->ssh->exec("docker exec {$appSlug} curl -k -s -o /dev/null -w '%{http_code}' http://localhost:{$containerPort}/ --max-time 3 2>/dev/null || echo '000'"));
                if (in_array($rootCheck, $allowedCodes) || ($rootCheck > 0 && $rootCheck < 500)) {
                    $this->streamer->log($deployment, "⚠️ Internal Health endpoint {$hcPath} returned {$check}, but Root (/) is responsive ({$rootCheck}). Proceeding.", LogType::WARNING);
                    $success = true;
                    break;
                }
            }

            // Test 3: Emergency override (Container is running but HTTP is slow/weird)
            if ($app->ignore_healthcheck_warnings && $check > 0 && $check < 500) {
                $this->streamer->log($deployment, "⚠️ App is slow/returning {$check}, but ignore_healthcheck_warnings is active. Proceeding.", LogType::WARNING);
                $success = true;
                break;
            }

            $this->streamer->log($deployment, "⏳ Waiting for app to become healthy... ({$attempt}/{$maxAttempts})", LogType::DEBUG);
            sleep(3);
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
        $server = $deployment->application?->server;
        if (!$server || $server->infrastructure_type !== 'clean') {
            return;
        }

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

        // Notification proactive quand terminé
        if ($isFinished) {
            $level = $status === DeploymentStatus::SUCCESS ? 'success' : 'error';
            $title = $status === DeploymentStatus::SUCCESS ? 'Déploiement réussi' : 'Déploiement échoué';
            $message = $status === DeploymentStatus::SUCCESS
                ? "L'application **{$app->name}** a été déployée avec succès."
                : "Le déploiement de **{$app->name}** a échoué. Consultez les logs pour plus de détails.";

            $app->user->notify(new \App\Notifications\VpslyNotification(
                $title,
                $message,
                $level,
                'globe',
                "/apps/{$app->id}"
            ));
        }
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
                '-v /var/lib/vpsly/traefik/letsencrypt:/letsencrypt',
                'traefik:v3.6',
                '--api.insecure=true',
                '--log.level=INFO',
                '--providers.docker=true',
                '--providers.docker.exposedbydefault=false',
                '--providers.docker.network=vpsly',
                '--entrypoints.web.address=:80',
                '--entrypoints.web.http.redirections.entryPoint.to=websecure',
                '--entrypoints.web.http.redirections.entryPoint.scheme=https',
                '--entrypoints.websecure.address=:443',
                '--certificatesresolvers.vpsly.acme.tlschallenge=true',
                '--certificatesresolvers.vpsly.acme.email=contact@vpsly.tech',
                '--certificatesresolvers.vpsly.acme.storage=/letsencrypt/acme.json'
            ]);
            $this->ssh->exec($command);
        }
    }

    /**
     * Déploiement Legacy : Exécution directe de scripts SSH dans un dossier existant.
     */
    /**
     * Gère le déploiement sur une infrastructure Legacy (non-dockerisée).
     */
    protected function deployLegacy(Application $app, Server $server, Deployment $deployment): void
    {
        if ($app->deployment_mode === 'legacy_new') {
            $this->deployLegacyNew($app, $server, $deployment);
            return;
        }

        $this->deployLegacySimple($app, $server, $deployment);
    }

    /**
     * Mode Simple : Déploiement direct dans le dossier cible.
     */
    protected function deployLegacySimple(Application $app, Server $server, Deployment $deployment): void
    {
        $this->updateStatus($app, $deployment, DeploymentStatus::PREPARING);
        $this->streamer->log($deployment, " Connecting for Legacy Simple Deployment...", LogType::INFO);

        $this->ssh->connect($server);
        $targetPath = $app->target_path;
        $targetPathEscaped = escapeshellarg($targetPath);

        // 1. Structure
        $this->ssh->exec("mkdir -p {$targetPathEscaped}");

        // 2. Git (Toujours en premier pour pouvoir importer si nouveau)
        if (!empty($app->repo_url)) {
            $this->updateStatus($app, $deployment, DeploymentStatus::CLONING);
            $this->git->sync($app->repo_url, $app->branch ?? 'main', $targetPath, $app->user->github_token);
            
            // TENTATIVE D'IMPORTATION (WOW effect: lit le .env ou .env.example après le clone)
            $this->legacyConfig->importFromRemote($app, $targetPath);
        }

        // 3. .env (Source of Truth - Écrase avec les variables du dashboard + importées)
        $this->streamer->log($deployment, " Synchronizing .env...", LogType::INFO);
        $this->legacyConfig->syncConfiguration($app);

        // 3.5 Auto-bootstrap Laravel (APP_KEY) — Détection simplifiée
        $hasArtisan = !str_contains(
            $this->ssh->exec("test -f " . escapeshellarg($targetPath . '/artisan') . " && echo 'yes' || echo 'no'"),
            'no'
        );
        if ($hasArtisan) {
            $this->bootstrapLaravel($app, $deployment, $targetPath);
        }

        // 4. Script
        $this->executeLegacyScript($app, $deployment, $targetPath);

        // 5. Fix Permissions (Auto-pilot for PHP/Laravel)
        $stack = $hasArtisan ? 'php' : 'generic';
        $this->provisioner->fixPermissions($app, $stack);

        $this->updateStatus($app, $deployment, DeploymentStatus::SUCCESS);
        $this->streamer->log($deployment, " Simple deployment successful!", LogType::SUCCESS);
        $this->ssh->disconnect();
    }

    /**
     * Mode Legacy New : Déploiement automatisé avec Nixpacks + Nginx + Certbot.
     */
    protected function deployLegacyNew(Application $app, Server $server, Deployment $deployment): void
    {
        $this->updateStatus($app, $deployment, DeploymentStatus::PREPARING);
        $this->streamer->log($deployment, " Starting Automated Legacy Deployment...", LogType::INFO);

        $this->ssh->connect($server);
        $targetPath = $app->target_path;
        $targetPathEscaped = escapeshellarg($targetPath);

        // 1. Structure
        $this->ssh->exec("mkdir -p {$targetPathEscaped}");

        // 2. Git
        $this->updateStatus($app, $deployment, DeploymentStatus::CLONING);
        $this->git->sync($app->repo_url, $app->branch ?? 'main', $targetPath, $app->user->github_token);

        // 3. Nixpacks Detection (Nouveau)
        $this->streamer->log($deployment, " Analyze du projet...", LogType::INFO);
        $stack = $this->provisioner->detectStack($app, $targetPath);
        $this->streamer->log($deployment, " Stack detecté: {$stack}", LogType::SUCCESS);

        // 4. .env (Import from .env.example first if new)
        if (!$app->nginx_configured) {
            $this->streamer->log($deployment, " Importation de .env...", LogType::INFO);
            $imported = $this->legacyConfig->importFromRemote($app, $targetPath);
            $this->streamer->log($deployment, " Imported {$imported} variables from remote.", LogType::INFO);
        }
        $this->streamer->log($deployment, " Synchronisation du  .env...", LogType::INFO);
        $this->legacyConfig->syncConfiguration($app);

        // 5. Script (Build)
        $this->updateStatus($app, $deployment, DeploymentStatus::BUILDING);
        $this->executeLegacyScript($app, $deployment, $targetPath);

        // 5.5 Auto-bootstrap Laravel (APP_KEY) - AFTER script to ensure vendor exists
        if ($stack === 'php') {
            $this->bootstrapLaravel($app, $deployment, $targetPath);
        }

        // 5.6 Fix Permissions (Auto-pilot for PHP/Laravel)
        $this->provisioner->fixPermissions($app, $stack);

        // 6. Health Check Local (Sauf PHP)
        if ($stack !== 'php') {
            $this->streamer->log($deployment, " Test de santé local...", LogType::INFO);
            $isHealthy = $this->provisioner->localHealthCheck($app, $stack);
            if (!$isHealthy) {
                throw new Exception("Le test de santé local a échoué. Vérifiez les logs.");
            }
        }

        // 7. Nginx & SSL (Uniquement si pas encore configuré)
        if (!$app->nginx_configured) {
            $this->streamer->log($deployment, " Configuration du Nginx & SSL...", LogType::INFO);
            $this->provisioner->provisionWebserver($app, $stack);
            $app->update(['nginx_configured' => true]);
        }

        // 8. Health Check Final
        $this->streamer->log($deployment, " Vérification du déploiement final...", LogType::INFO);
        $this->provisioner->finalHealthCheck($app);

        $this->updateStatus($app, $deployment, DeploymentStatus::SUCCESS);
        $this->streamer->log($deployment, " Déploiement réussi!", LogType::SUCCESS);
        $this->ssh->disconnect();
    }

    /**
     * Mode Professionnel : releases/current avec Zéro-Downtime.
     */
    protected function deployLegacyProfessional(Application $app, Server $server, Deployment $deployment): void
    {
        $this->updateStatus($app, $deployment, DeploymentStatus::PREPARING);
        $this->streamer->log($deployment, " Connecting for Legacy Professional Deployment...", LogType::INFO);

        $this->ssh->connect($server);
        
        $rootPath = rtrim($app->target_path, '/');
        $rootPathEscaped = escapeshellarg($rootPath);
        $releasesPath = "{$rootPath}/releases";
        $sharedPath = "{$rootPath}/shared";
        $currentPath = "{$rootPath}/current";
        $releaseId = date('YmdHis');
        $releasePath = "{$releasesPath}/{$releaseId}";
        $releasePathEscaped = escapeshellarg($releasePath);
        $sharedPathEscaped = escapeshellarg($sharedPath);
        $currentPathEscaped = escapeshellarg($currentPath);
        $releasesPathEscaped = escapeshellarg($releasesPath);

        // 1. Création de la structure
        $this->streamer->log($deployment, " Preparing directory structure...", LogType::INFO);
        $this->ssh->exec("mkdir -p {$releasesPathEscaped} {$sharedPathEscaped}");

        // 2. Git Clone dans le dossier de release
        if (!empty($app->repo_url)) {
            $this->updateStatus($app, $deployment, DeploymentStatus::CLONING);
            $this->streamer->log($deployment, " Cloning into release {$releaseId}...", LogType::INFO);
            $this->git->sync($app->repo_url, $app->branch ?? 'main', $releasePath, $app->user->github_token);

            // IMPORT INITIAL DEPUIS LA RELEASE (Utile pour .env.example)
            $this->legacyConfig->importFromRemote($app, $releasePath);
        } else {
            $this->ssh->exec("mkdir -p \"{$releasePath}\"");
        }


        // 3. Gestion du .env (dans shared)
        $this->streamer->log($deployment, " Synchronizing .env (shared)...", LogType::INFO);
        // On synchronise temporairement dans le root ou directement via le service si on l'adapte
        // Pour l'instant on réutilise le service qui écrit dans $app->target_path/.env
        // Mais en Pro, le target_path devrait être le ROOT.
        $this->legacyConfig->syncConfiguration($app); 
        // On déplace vers shared et on link
        $this->ssh->exec("mv {$rootPathEscaped}/.env {$sharedPathEscaped}/.env 2>/dev/null || true");
        $this->ssh->exec("ln -sfn {$sharedPathEscaped}/.env {$releasePathEscaped}/.env");

        // 4. Gestion des volumes persistants (Persistent Directories)
        $this->streamer->log($deployment, " Linking persistent directories (shared)...", LogType::INFO);
        foreach ($app->persistentVolumes as $volume) {
            $mountPath = ltrim($volume->mount_path, '/');
            $sharedVolPath = "{$sharedPath}/{$mountPath}";
            $releaseVolPath = "{$releasePath}/{$mountPath}";

            $sharedVolPathEscaped = escapeshellarg($sharedVolPath);
            $releaseVolPathEscaped = escapeshellarg($releaseVolPath);

            // S'assurer que le dossier partagé existe
            $this->ssh->exec("mkdir -p {$sharedVolPathEscaped}");
            
            // Supprimer le dossier s'il a été cloné par Git pour pouvoir mettre le lien
            $this->ssh->exec("rm -rf {$releaseVolPathEscaped}");
            
            // Créer le lien symbolique
            $this->ssh->exec("ln -sfn {$sharedVolPathEscaped} {$releaseVolPathEscaped}");
        }

        // 5. Script d'installation/build
        $this->executeLegacyScript($app, $deployment, $releasePath);

        // 5.1 Auto-bootstrap Laravel (APP_KEY)
        $this->bootstrapLaravel($app, $deployment, $releasePath);

        // 5.5 Fix Permissions (Auto-pilot for PHP/Laravel)
        $this->provisioner->fixPermissions($app, 'php'); // On force pour l'instant

        // 6. Switch Atomique (Zéro Downtime)
        $this->streamer->log($deployment, " Switching to new release...", LogType::INFO);
        $this->ssh->exec("ln -sfn {$releasePathEscaped} {$currentPathEscaped}");

        // 7. Nettoyage des anciennes releases (garder les 5 dernières)
        $this->ssh->exec("cd {$releasesPathEscaped} && ls -1t | tail -n +6 | xargs rm -rf");

        $this->updateStatus($app, $deployment, DeploymentStatus::SUCCESS);
        $this->streamer->log($deployment, " Professional deployment successful!", LogType::SUCCESS);
        $this->ssh->disconnect();
    }

    /**
     * Exécute le script personnalisé dans un dossier donné.
     */
    protected function executeLegacyScript(Application $app, Deployment $deployment, string $path): void
    {
        $script = $app->deploy_script;
        if (empty($script)) return;

        $this->updateStatus($app, $deployment, DeploymentStatus::DEPLOYING);
        $this->streamer->log($deployment, " Running custom script in {$path}...", LogType::INFO);

        $commands = collect(explode("\n", $script))
            ->map(fn($cmd) => trim($cmd))
            ->filter(fn($cmd) => !empty($cmd) && !str_starts_with($cmd, '#'))
            ->map(fn($cmd) => $this->optimizePm2Command($cmd));

        // Augmenter le timeout pour les scripts qui peuvent être longs (ex: build npm)
        $oldTimeout = $this->ssh->getTimeout();
        $this->ssh->setTimeout(300); // 5 minutes par commande

        try {
            foreach ($commands as $command) {
                $this->streamer->log($deployment, " $ {$command}", LogType::INFO);
                $pathEscaped = escapeshellarg($path);
                $this->ssh->stream("cd {$pathEscaped} && {$command}", function ($line) use ($deployment) {
                    $this->streamer->log($deployment, $line, LogType::DEBUG);
                });
            }
        } finally {
            // Restaurer le timeout d'origine
            $this->ssh->setTimeout($oldTimeout);
        }
    }

    /**
     * Optimise silencieusement les commandes PM2 pour éviter les doublons.
     * Pattern: pm2 describe NAME && pm2 restart NAME || pm2 start ...
     */
    protected function optimizePm2Command(string $command): string
    {
        if (
            str_contains($command, 'pm2 start') &&
            str_contains($command, '--name') &&
            !str_contains($command, 'pm2 describe') &&
            !str_contains($command, 'pm2 restart') &&
            !str_contains($command, 'pm2 reload') &&
            !str_contains($command, 'pm2 delete')
        ) {
            // Extraction du nom avec support quotes
            if (preg_match('/--name\s+["\']?([^"\s\']+)["\']?/', $command, $matches)) {
                $name = escapeshellarg($matches[1]);
                return "pm2 describe {$name} > /dev/null 2>&1 && pm2 restart {$name} || {$command}";
            }
        }

        return $command;
    }

    /**
     * Helper pour scanner le plan Nixpacks.
     */
    protected function planContains(array $plan, string $keyword): bool
    {
        $jsonStr = strtolower(json_encode($plan));
        return str_contains($jsonStr, $keyword);
    }

    /**
     * Auto-bootstrap Laravel : génère APP_KEY si manquant et re-sync.
     * Résout le crash "No application encryption key has been specified".
     */
    protected function bootstrapLaravel(Application $app, Deployment $deployment, string $targetPath): void
    {
        $pathEscaped = escapeshellarg($targetPath);

        // 1. Vérifier si APP_KEY est vide ou manquant dans la DB VPSly
        $appKey = $app->environmentVariables()->where('key', 'APP_KEY')->first();
        $keyValue = $appKey?->value ?? '';

        if (!empty($keyValue) && str_starts_with($keyValue, 'base64:')) {
            // APP_KEY déjà valide, rien à faire
            return;
        }

        $this->streamer->log($deployment, " Generating Laravel APP_KEY...", LogType::INFO);

        // 2. Générer la clé directement sur le serveur
        $output = $this->ssh->exec("cd {$pathEscaped} && php artisan key:generate --show 2>&1");
        $generatedKey = trim($output);

        if (!str_starts_with($generatedKey, 'base64:')) {
            $this->streamer->log($deployment, "⚠️ APP_KEY generation returned unexpected output: {$generatedKey}", LogType::WARNING);
            return;
        }

        // 3. Sauvegarder dans la DB VPSly
        $app->environmentVariables()->updateOrCreate(
            ['key' => 'APP_KEY'],
            ['value' => $generatedKey, 'is_secret' => true]
        );

        // 4. Re-sync le .env sur le serveur (avec la nouvelle clé)
        $this->legacyConfig->syncConfiguration($app);

        $this->streamer->log($deployment, " APP_KEY generated and synchronized.", LogType::SUCCESS);
    }
}

