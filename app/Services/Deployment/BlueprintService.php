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
        // 1. Docker Compose (utilise l'image déjà buildée par Nixpacks)
        $compose = $this->getCompose($app, $imageName, $nixpacksPlan);
        $this->writeRemoteFile($appPath . '/docker-compose.yml', $compose);
    }

    protected function getCompose(Application $app, string $imageName, array $nixpacksPlan): string
    {
        $path = resource_path("stubs/stacks/common/docker-compose.yml.stub");
        if (!File::exists($path)) {
             $path = resource_path("stubs/stacks/common/docker-compose.yml");
        }
        
        $content = File::get($path);
        
        // Convention : on détecte SEULEMENT PHP (cas spécial).
        // Tout le reste = port 3000 par défaut (Node, Python, Go, etc.)
        $isPhp = $this->planContains($nixpacksPlan, 'php');
        $containerPort = $isPhp ? 80 : 3000;

          // Variables de runtime
        $appSlug = strtolower(preg_replace('/[^a-z0-9\-]/', '-', $app->name));
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

        // Construction du bloc environment (format mapping YAML, indent 6)
        $envBlock = "";
        foreach ($envVars as $key => $value) {
            $envBlock .= "      {$key}: \"{$value}\"\n";
        }

        $serverIp = $app->server->ip ?? '127.0.0.1';
        $domain = "{$appSlug}.{$serverIp}.sslip.io";

        // Remplacement des variables dans le template
        $replacements = [
            '{{APP_NAME}}'  => $appSlug,
            '{{APP_ID}}'    => $app->id,
            '{{DOMAIN}}'    => $domain,
            '{{APP_PORT}}'  => $containerPort,
            '{{IMAGE_NAME}}' => $imageName,
            '{{ENVIRONMENT}}' => rtrim($envBlock),
        ];

        return str_replace(array_keys($replacements), array_values($replacements), $content);
    }

    protected function writeRemoteFile(string $filePath, string $content): void
    {
        $base64 = base64_encode($content);
        $fileName = basename($filePath);
        
        // On utilise base64 pour garantir que le shell n'interprète rien
        $command = "echo '{$base64}' | base64 -d > \"{$filePath}\"";
        
        $this->ssh->exec($command);
        
        \Log::info("[Blueprint] File written via Base64", [
            'file' => $fileName,
            'size' => strlen($content),
            'b64_size' => strlen($base64)
        ]);
    }

    /**
     * Détecte si le plan Nixpacks contient un provider donné.
     * Scanne plusieurs niveaux de la structure JSON pour une détection robuste.
     */
    protected function planContains(array $plan, string $keyword): bool
    {
        // Niveau 1 : providers directs (ex: ["node", "npm"])
        $providers = data_get($plan, 'providers', []);
        if (is_array($providers)) {
            foreach ($providers as $provider) {
                if (is_string($provider) && str_contains(strtolower($provider), $keyword)) {
                    return true;
                }
            }
        }

        // Niveau 2 : nixPkgs dans la phase setup (ex: ["nodejs_20", "npm-9_x"])
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

        // Niveau 3 : commande de démarrage (ex: "npm run start", "php artisan serve")
        $startCmd = data_get($plan, 'start.cmd', '');
        if (is_string($startCmd) && str_contains(strtolower($startCmd), $keyword)) {
            return true;
        }

        // Niveau 4 : scan récursif du plan JSON sérialisé (filet de sécurité)
        $jsonStr = strtolower(json_encode($plan));
        if (str_contains($jsonStr, '"' . $keyword)) {
            return true;
        }

        return false;
    }
}

