<?php

namespace App\Services\Deployment;

use App\Models\Application;
use Illuminate\Support\Facades\File;
use Exception;

class BlueprintService
{
    public function __construct(protected SSHService $ssh, protected ProxyService $proxy) {}

    /**
     * Génère et écrit uniquement le fichier docker-compose.yml sur le VPS.
     * Le build est désormais géré par Nixpacks en amont.
     */
    public function syncConfiguration(Application $app, string $imageName, string $appPath, array $nixpacksPlan = []): void
    {
        // 1. Génération du fichier .env (Variables Système + Utilisateur)
        $this->syncEnvironmentVariables($app, $appPath, $nixpacksPlan);

        // 2. Docker Compose (utilise l'image déjà buildée par Nixpacks)
        $compose = $this->getCompose($app, $imageName, $nixpacksPlan);
        $this->writeRemoteFile($appPath . '/docker-compose.yml', $compose);
    }

    /**
     * Génère et écrit le fichier .env de manière atomique sur le VPS.
     */
    protected function syncEnvironmentVariables(Application $app, string $appPath, array $nixpacksPlan): void
    {
        // 1. Variables système par défaut
        $appSlug = strtolower(preg_replace('/[^a-z0-9\-]/', '-', $app->name));
        $isPhp = $this->planContains($nixpacksPlan, 'php');
        
        $envVars = [
            'APP_NAME' => $appSlug,
            'APP_ENV' => 'production',
        ];

        if ($isPhp) {
            $envVars['NIXPACKS_PHP_ROOT_DIR'] = '/app/public';
            $envVars['NIXPACKS_PHP_FALLBACK_PATH'] = '/index.php';
        } else {
            $envVars['NODE_ENV'] = 'production';
            $envVars['PORT'] = '3000';
        }

        // 2. Variables configurées par l'utilisateur en base
        $userVars = $app->environmentVariables()->get();
        foreach ($userVars as $var) {
            $envVars[$var->key] = $var->value;
        }

        // 3. Formatage pour le fichier .env
        $content = "";
        foreach ($envVars as $key => $value) {
            $content .= "{$key}=\"{$value}\"\n";
        }

        // 4. Écriture atomique
        $this->writeAtomicRemoteFile($appPath . '/.env', $content);
    }

    protected function getCompose(Application $app, string $imageName, array $nixpacksPlan): string
    {
        $path = resource_path("stubs/stacks/common/docker-compose.yml.stub");
        if (!File::exists($path)) {
             $path = resource_path("stubs/stacks/common/docker-compose.yml");
        }
        
        $content = File::get($path);
        
        $isPhp = $this->planContains($nixpacksPlan, 'php');
        $containerPort = $isPhp ? 80 : 3000;
        $appSlug = strtolower(preg_replace('/[^a-z0-9\-]/', '-', $app->name));

        $serverIp = $app->server->ip ?? '127.0.0.1';
        $domain = "{$appSlug}.{$serverIp}.sslip.io";

        // ENVIRONMENT reste vide ici car on utilise env_file: .env dans le stub
        $envBlock = "";

        $replacements = [
            '{{APP_NAME}}'  => $appSlug,
            '{{APP_ID}}'    => $app->id,
            '{{DOMAIN}}'    => $domain,
            '{{APP_PORT}}'  => $containerPort,
            '{{IMAGE_NAME}}' => $imageName,
            '{{ENVIRONMENT}}' => $envBlock,
        ];

        return str_replace(array_keys($replacements), array_values($replacements), $content);
    }

    protected function writeRemoteFile(string $filePath, string $content): void
    {
        $base64 = base64_encode($content);
        $command = "echo '{$base64}' | base64 -d > \"{$filePath}\"";
        $this->ssh->exec($command);
    }

    /**
     * Écrit un fichier de manière atomique sur le VPS (tmp -> chmod -> mv).
     */
    protected function writeAtomicRemoteFile(string $filePath, string $content): void
    {
        $base64 = base64_encode($content);
        $tmpPath = $filePath . '.tmp';
        
        // Processus : écriture tmp -> sécurisation -> renommage atomique
        $command = "echo '{$base64}' | base64 -d > \"{$tmpPath}\" && chmod 600 \"{$tmpPath}\" && mv \"{$tmpPath}\" \"{$filePath}\"";
        
        $this->ssh->exec($command);
        
        \Log::info("[Blueprint] Atomic file write completed", [
            'file' => basename($filePath),
            'size' => strlen($content)
        ]);
    }

    /**
     * Détecte si le plan Nixpacks contient un provider donné.
     * Scanne plusieurs niveaux de la structure JSON pour une détection robuste.
     */
    protected function planContains(array $plan, string $keyword): bool
    {
        $providers = data_get($plan, 'providers', []);
        if (is_array($providers)) {
            foreach ($providers as $provider) {
                if (is_string($provider) && str_contains(strtolower($provider), $keyword)) {
                    return true;
                }
            }
        }

        $nixPkgs = data_get($plan, 'phases.setup.nixPkgs', []);
        if (empty($nixPkgs)) {
            $nixPkgs = data_get($plan, 'phases.setup.nixpkgs', []);
        }
        if (is_array($nixPkgs)) {
            foreach ($nixPkgs as $pkg) {
                if (is_string($pkg) && str_contains(strtolower($pkg), $keyword)) {
                    return true;
                }
            }
        }

        $startCmd = data_get($plan, 'start.cmd', '');
        if (is_string($startCmd) && str_contains(strtolower($startCmd), $keyword)) {
            return true;
        }

        $jsonStr = strtolower(json_encode($plan));
        if (str_contains($jsonStr, '"' . $keyword)) {
            return true;
        }

        return false;
    }
}
