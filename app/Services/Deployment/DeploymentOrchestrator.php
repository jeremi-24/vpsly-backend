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
        protected LogStreamer $streamer
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

            Log::info("[Deploy] Step 1: Connecting to SSH...");
            $this->streamer->log($deployment, "Connecting to VPS {$server->ip}...", LogType::INFO);

            $this->ssh->connect($server);

            // S'assurer que les outils de base sont là
            $this->docker->ensureInstalled($server, $deployment);
            $this->nixpacks->ensureInstalled($server, $deployment);
            $this->ensureTraefik($server, $deployment);

            $appPath = "/var/www/vpsly/apps/" . Str::slug($app->name);
            $this->ssh->exec("mkdir -p \"{$appPath}\"");

            $this->streamer->log($deployment, "Connected. Workspace ready at {$appPath}", LogType::SUCCESS);

            // STEP 2: CLONING
            $this->updateStatus($app, $deployment, DeploymentStatus::CLONING);
            $this->streamer->log($deployment, "Synchronizing code from {$app->repo_url}...", LogType::INFO);

            $this->git->sync($app->repo_url, $app->branch, $appPath, $app->user->github_token);
            $this->streamer->log($deployment, "Code synchronized (Branch: {$app->branch}).", LogType::SUCCESS);

            // NETTOYAGE : Invalidation chirurgicale du cache Nixpacks
            // Cela force un nouveau plan mais préserve le cache Docker pour les couches inchangées.
            $this->ssh->exec("rm -rf \"{$appPath}/.nixpacks\"");

            // ANALYSE : Résolution de la version Node
            $nodeVersion = $this->resolveNodeVersion($appPath, $deployment);

            // ANCRAGE : Écriture du fichier .node-version (mécanisme natif Nixpacks)
            // Nixpacks lit ce fichier en priorité pour déterminer la version Node.
            // C'est plus fiable que la variable d'environnement shell.
            $this->ssh->exec("echo \"{$nodeVersion}\" > \"{$appPath}/.node-version\"");
            $this->streamer->log($deployment, "📌 Fichier .node-version créé (Node {$nodeVersion}).", LogType::DEBUG);

            // PURGE : Le cache Docker builder contient les layers avec l'ancienne version Node.
            // On le purge pour forcer la reconstruction de la layer nix-env.
            $this->ssh->exec("docker builder prune -f 2>/dev/null || true");

            // STEP 3: BUILDING (Nixpacks Engine)
            $this->updateStatus($app, $deployment, DeploymentStatus::BUILDING);

            // ANALYSE : On récupère le plan nixpacks pour identifier la stack
            $nixpacksPlan = $this->nixpacks->getPlan($server, $deployment, $appPath, $nodeVersion);

            $imageName = "vpsly-app-{$app->id}";
            $this->nixpacks->build($server, $deployment, $appPath, $imageName, $nodeVersion);

            // STEP 4: DEPLOYING (Docker Compose)
            $this->updateStatus($app, $deployment, DeploymentStatus::DEPLOYING);
            $this->streamer->log($deployment, "Génération de la configuration Docker Compose...", LogType::INFO);

            // BACKUP horodaté du .env existant (si présent)
            $this->ssh->exec("cd \"{$appPath}\" && cp .env .env.backup.$(date +%s) 2>/dev/null || true");

            $this->blueprint->syncConfiguration($app, $imageName, $appPath, $nixpacksPlan);

            // SÉCURISATION finale des permissions
            $this->ssh->exec("cd \"{$appPath}\" && chown www-data:www-data .env && chmod 600 .env");

            $this->streamer->log($deployment, "Démarrage des conteneurs...", LogType::INFO);

            $this->ssh->exec("docker network ls | grep -q 'vpsly_network' || docker network create vpsly_network");

            try {
                // On n'utilise plus --build car l'image est déjà faite par Nixpacks
                $this->ssh->stream("cd \"{$appPath}\" && docker compose up -d", function ($line) use ($deployment) {
                    $this->streamer->log($deployment, $line, LogType::DEBUG);
                });
            } catch (Exception $e) {
                throw new \App\Exceptions\Deployment\NonRetryableException("Docker Up failed: " . $e->getMessage(), 0, $e);
            }


            // STEP 5: VERIFY (Health Check)
            $this->verify($app, $deployment, $appPath, $nixpacksPlan);

            // FINAL STEP: SUCCESS
            $this->updateStatus($app, $deployment, DeploymentStatus::SUCCESS);
            $this->streamer->log($deployment, "Les conteneurs ont démarré avec succès! Votre application est maintenant en ligne.", LogType::SUCCESS);

            // CLEANUP
            $this->cleanup($deployment);

        } catch (Exception $e) {
            $msg = "CRITICAL DEPLOYMENT ERROR: " . $e->getMessage();
            $this->streamer->log($deployment, $msg, LogType::ERROR);

            try {
                $this->updateStatus($app, $deployment, DeploymentStatus::FAILED);
            } catch (Exception $dbEx) {
                // Si la DB échoue aussi, on log le message brut sans crash total du catch
                $this->streamer->log($deployment, "Erreur d'enregistrement du statut de déploiement: " . $dbEx->getMessage(), LogType::DEBUG);
            }

            throw $e;

        } finally {
            $this->streamer->flush($deployment);
            $this->ssh->disconnect();
        }
    }

    /**
     * Vérifie que l'application répond réellement via HTTP.
     * Hybride : Localhost (vitesse) + Public Domain (propagation Traefik).
     */
    protected function verify(Application $app, Deployment $deployment, string $appPath, array $nixpacksPlan): void
    {
        // 1. Résolution irréfutable du nom de conteneur
        $containerId = trim($this->ssh->exec("cd \"{$appPath}\" && docker compose ps -q 2>/dev/null | head -1"));
        $containerName = trim($this->ssh->exec("docker inspect --format='{{.Name}}' {$containerId} 2>/dev/null | sed 's/\///'"));

        if (empty($containerName)) {
            $containerName = strtolower(preg_replace('/[^a-z0-9\-]/', '-', $app->name));
        }

        // 2. Détermination intelligente du port
        $port = trim($this->ssh->exec(
            "docker inspect --format='{{range .Config.Env}}{{println .}}{{end}}' {$containerName} 2>/dev/null | grep '^PORT=' | cut -d'=' -f2"
        ));

        if (empty($port)) {
            // Détections basées sur le plan Nixpacks (cohérent avec BlueprintService)
            $isPhp = $this->planContains($nixpacksPlan, 'php');
            $port = $isPhp ? '80' : '3000';
        }

        // 3. Préparation DNS
        $serverIp = $app->server->ip ?? '127.0.0.1';
        $appSlug = strtolower(preg_replace('/[^a-z0-9\-]/', '-', $app->name));
        $domain = "{$appSlug}.{$serverIp}.sslip.io";

        $this->streamer->log($deployment, "🔍 Health check (container={$containerName}, port={$port}, domain={$domain})", LogType::INFO);

        $maxAttempts = 18; // 90s
        $attempt = 0;
        $active = false;

        while ($attempt < $maxAttempts) {
            $attempt++;

            // État Docker
            $state = trim($this->ssh->exec("docker inspect --format='{{.State.Status}}' {$containerName} 2>/dev/null || echo 'missing'"));
            if ($state !== 'running') {
                $this->streamer->log($deployment, "⏳ Waiting for container... ({$attempt}/{$maxAttempts})", LogType::DEBUG);
                sleep(5);
                continue;
            }

            // Health Check Hybride : Local (Source de vérité app) OR Public (Source de vérité routing)
            $localCheck = trim($this->ssh->exec("docker exec {$containerName} curl -s -o /dev/null -w '%{http_code}' http://localhost:{$port} 2>/dev/null || echo '000'"));
            $publicCheck = trim($this->ssh->exec("curl -k -s -o /dev/null -w '%{http_code}' https://{$domain} --max-time 2 2>/dev/null || echo '000'"));

            $isLocalOk = in_array($localCheck, ['200', '301', '302', '304']);
            $isPublicOk = in_array($publicCheck, ['200', '301', '302', '304']);

            if ($isLocalOk || $isPublicOk) {
                $active = true;
                $source = $isPublicOk ? "Public Domain" : "Localhost";
                $code = $isPublicOk ? $publicCheck : $localCheck;
                $this->streamer->log($deployment, "✅ App responsive via {$source} (HTTP {$code})", LogType::DEBUG);
                break;
            }

            $this->streamer->log($deployment, "⏳ Waiting for response... (Local: {$localCheck}, Public: {$publicCheck})", LogType::DEBUG);
            sleep(5);
        }

        if (!$active) {
            $logs = $this->ssh->exec("docker logs {$containerName} --tail 50 2>&1");
            $this->streamer->log($deployment, "📋 Container logs:\n{$logs}", LogType::ERROR);
            throw new \App\Exceptions\Deployment\NonRetryableException("Health check failed after 90s. The app is not responding.");
        }

        $this->streamer->log($deployment, "✅ Déploiement validé avec succès.", LogType::SUCCESS);
    }

    /**
     * Nettoie les images résiduelles pour économiser l'espace disque sur le VPS.
     */
    protected function cleanup(Deployment $deployment): void
    {
        try {
            $this->streamer->log($deployment, "Pruning unused Docker images on VPS...", LogType::DEBUG);
            $this->ssh->exec("docker image prune -f");
        } catch (Exception $e) {
            // Un échec de cleanup ne doit pas faire échouer le déploiement
            $this->streamer->log($deployment, "Cleanup warning: " . $e->getMessage(), LogType::DEBUG);
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

        // Diffusion du statut en temps réel via WebSocket
        event(new \App\Events\DeploymentStatusUpdatedEvent(
            $deployment->id,
            $app->id,
            $status->value,
            !$isFinished
        ));
    }
    /**
     * Résout la version de Node.js à utiliser en lisant le package.json du projet.
     */
    protected function resolveNodeVersion(string $appPath, Deployment $deployment): string
    {
        $fallbackVersion = "20"; // Node 20 est le standard de base pour les apps modernes

        try {
            $this->streamer->log($deployment, "🔍 Détection de la version Node.js requise...", LogType::DEBUG);

            // On lit le package.json directement sur le VPS
            $json = $this->ssh->exec("cat \"{$appPath}/package.json\" 2>/dev/null || echo 'not found'");

            if (trim($json) === 'not found' || empty($json)) {
                $this->streamer->log($deployment, "Fichier package.json non trouvé. Utilisation de la version par défaut (Node {$fallbackVersion}).", LogType::DEBUG);
                return $fallbackVersion;
            }

            $data = json_decode($json, true);
            $enginesNode = data_get($data, 'engines.node');

            if (!$enginesNode) {
                $this->streamer->log($deployment, "Aucune version Node spécifiée dans package.json. Utilisation de Node {$fallbackVersion}.", LogType::DEBUG);
                return $fallbackVersion;
            }

            // Extraction de la version majeure via Regex
            // Supporte: ">=20.9.0", "^18.0.0", "16.x", "14" etc.
            if (preg_match('/(\d+)(?:\.\d+)*/', $enginesNode, $matches)) {
                $version = $matches[1];

                // Sécurité : Si la version détectée est < 18, on conseille 20
                if (intval($version) < 18) {
                    $this->streamer->log($deployment, "⚠️ Version Node {$version} détectée (trop ancienne). Forçage vers Node {$fallbackVersion} pour stabilité.", LogType::DEBUG);
                    return $fallbackVersion;
                }

                $this->streamer->log($deployment, "✅ Version Node {$version} détectée et sélectionnée.", LogType::INFO);
                return $version;
            }

            return $fallbackVersion;
        } catch (\Exception $e) {
            Log::warning("[Orchestrator] Node resolution failed: " . $e->getMessage());
            return $fallbackVersion;
        }
    }

    /**
     * Vérifie que Traefik est actif sur le serveur. Le lance si absent.
     * Utilise des arguments CLI pour Traefik v3 (plus robuste que le YAML).
     */
    protected function ensureTraefik(Server $server, Deployment $deployment): void
    {
        $this->streamer->log($deployment, "🔍 Stabilisation de l'infrastructure standard (Traefik)...", LogType::DEBUG);

        $baseDir = "/var/www/vpsly/traefik";

        // 0. S'assurer que les dossiers existent avec les bonnes permissions
        $this->ssh->exec("mkdir -p \"{$baseDir}/acme\"");
        $this->ssh->exec("touch \"{$baseDir}/acme/acme.json\" && chmod 600 \"{$baseDir}/acme/acme.json\"");

        // S'assurer que le réseau global existe
        $this->ssh->exec("docker network create vpsly_network 2>/dev/null || true");

        // 1. Détection de l'état actuel (Standard name: traefik)
        $check = $this->ssh->exec("docker ps --format '{{.Names}}' | grep -E '^traefik$' || true");
        $isRunning = !empty(trim($check));

        // On vérifie si c'est déjà la version CLI avec l'API modernisée (dans l'ENV) ou s'il faut migrer
        $env = $isRunning ? $this->ssh->exec("docker inspect traefik --format '{{range .Config.Env}}{{println .}}{{end}}'") : "";
        $cmd = $isRunning ? $this->ssh->exec("docker inspect traefik --format '{{.Config.Cmd}}'") : "";

        $isModern = str_contains($cmd, '--providers.docker') && str_contains($env, 'DOCKER_API_VERSION=1.41');

        // 2. Action corrective : Migration ou Installation
        if (!$isRunning || !$isModern) {
            $this->streamer->log($deployment, "⚠️ Migration vers l'instance standard Traefik v3 (CLI Mode)...", LogType::INFO);

            // Nettoyer tous les anciens emplacements et noms possibles
            $this->ssh->exec("docker rm -f traefik deploykit-gateway vpsly-traefik 2>/dev/null || true");
            $this->ssh->exec("rm -f /etc/traefik/traefik.yml 2>/dev/null || true");

            // Lancement du standard avec les arguments optimisés pour la v3
            $command = implode(' ', [
                'docker run -d --name traefik --restart always',
                '--network vpsly_network',
                '-p 80:80 -p 443:443',
                '-v /var/run/docker.sock:/var/run/docker.sock:ro',
                "-v \"{$baseDir}/acme:/acme\"",
                '-e DOCKER_API_VERSION=1.41',
                'traefik:v3.6',
                '--api.insecure=true',
                '--providers.docker=true',
                '--providers.docker.exposedbydefault=false',
                '--providers.docker.network=vpsly_network',
                '--entrypoints.web.address=:80',
                '--entrypoints.web.http.redirections.entryPoint.to=websecure',
                '--entrypoints.web.http.redirections.entryPoint.scheme=https',
                '--entrypoints.websecure.address=:443',
                '--certificatesresolvers.letsencrypt.acme.email=admin@vpsly.io',
                '--certificatesresolvers.letsencrypt.acme.storage=/acme/acme.json',
                '--certificatesresolvers.letsencrypt.acme.httpchallenge.entrypoint=web'
            ]);

            $this->ssh->exec($command);
            sleep(2);
        } else {
            // Déjà standard, on s'assure qu'il est bien démarré
            $this->ssh->exec("docker start traefik 2>/dev/null || true");
        }

        $this->streamer->log($deployment, "✅ Infrastructure réseau standardisée (traefik).", LogType::SUCCESS);
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

