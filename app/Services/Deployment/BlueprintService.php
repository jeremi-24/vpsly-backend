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
        
        $serverIp = $app->server->ip ?? '127.0.0.1';
        $domain = "{$appSlug}.{$serverIp}.sslip.io";

        $envVars = [
            'APP_NAME' => $appSlug,
            'APP_ENV' => 'production',
            'APP_URL' => "https://{$domain}",
        ];

        if ($isPhp) {
            // Nixpacks détecte nativement Laravel/PHP et configure le root sur /public.
            // On évite de forcer NIXPACKS_PHP_FALLBACK_PATH car cela peut créer des doublons de "location /" dans nginx.conf
            
            // Génération automatique de APP_KEY si absente (requis par Laravel)
            if (!$app->environmentVariables()->where('key', 'APP_KEY')->exists()) {
                $envVars['APP_KEY'] = 'base64:' . base64_encode(random_bytes(32));
            }
        } else {
            $envVars['NODE_ENV'] = 'production';
            $envVars['PORT'] = '3000';
        }

        // 2. Injection automatique des bases de données liées (Priorité standard)
        $databases = $app->databases()->with('server')->get();
        $dbTypesFound = [];
        
        foreach ($databases as $db) {
            $type = str_contains(strtolower($db->image), 'mysql') ? 'mysql' : 
                   (str_contains(strtolower($db->image), 'redis') ? 'redis' : 'postgres');

            $dbTypesFound[$type] = ($dbTypesFound[$type] ?? 0) + 1;
            
            $isSameServer = $db->server_id === $app->server_id;
            $host = $isSameServer ? $db->uuid : $db->server->ip;
            $port = $type === 'mysql' ? '3306' : ($type === 'redis' ? '6379' : '5432');
            
            // On définit des préfixes pour éviter les écrasements si plusieurs bases
            // La première base d'un type est la base "par défaut" (sans préfixe)
            // Les suivantes sont préfixées par leur nom (nettoyé)
            $isSecondary = $dbTypesFound[$type] > 1;
            $slugName = strtoupper(preg_replace('/[^a-z0-9]/i', '_', $db->name));
            $prefix = $isSecondary ? "{$slugName}_" : "";

            if ($type === 'redis') {
                $envVars["{$prefix}REDIS_HOST"] = $host;
                $envVars["{$prefix}REDIS_PORT"] = $port;
                if ($db->postgres_password) {
                    $envVars["{$prefix}REDIS_PASSWORD"] = $db->postgres_password;
                    $envVars["{$prefix}REDIS_URL"] = "redis://:{$db->postgres_password}@{$host}:{$port}";
                } else {
                    $envVars["{$prefix}REDIS_URL"] = "redis://{$host}:{$port}";
                }
            } else {
                $envVars["{$prefix}DB_CONNECTION"] = $type;
                $envVars["{$prefix}DB_HOST"] = $host;
                $envVars["{$prefix}DB_PORT"] = $port;
                $envVars["{$prefix}DB_DATABASE"] = $db->postgres_db;
                $envVars["{$prefix}DB_USERNAME"] = $db->postgres_user;
                $envVars["{$prefix}DB_PASSWORD"] = $db->postgres_password;
                
                $auth = "{$db->postgres_user}:{$db->postgres_password}";
                $envVars["{$prefix}DATABASE_URL"] = "{$type}://{$auth}@{$host}:{$port}/{$db->postgres_db}";
            }
        }

        // 3. Variables configurées manuellement par l'utilisateur (Priorité MAX - Écrase tout le reste)
        $userVars = $app->environmentVariables()->get();
        foreach ($userVars as $var) {
            $envVars[$var->key] = $var->value;
        }

        // 4. Formatage pour le fichier .env
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

        // Volumes
        $volumes = $app->persistentVolumes()->get();
        $volumeYaml = "";
        $volumeDefinitions = "";
        
        if ($volumes->count() > 0) {
            $volumeYaml = "    volumes:\n";
            $volumeDefinitions = "networks:\n"; // On rajoute networks car il est souvent avant
            
            foreach ($volumes as $vol) {
                // Si host_path est défini, c'est un bind mount, sinon c'est un volume nommé
                $source = $vol->host_path ?: $vol->name;
                $volumeYaml .= "      - \"{$source}:{$vol->mount_path}\"\n";
                
                if (!$vol->host_path) {
                    $volumeDefinitions .= "volumes:\n  {$vol->name}:\n    name: {$vol->name}\n";
                }
            }
        }

        // Resources Limits (Silent defaults)
        $memoryLimit = '512MB';
        $cpuLimit = '0.5';

        $resourcesYaml = "    deploy:\n";
        $resourcesYaml .= "      resources:\n";
        $resourcesYaml .= "        limits:\n";
        $resourcesYaml .= "          memory: {$memoryLimit}\n";
        $resourcesYaml .= "          cpus: '{$cpuLimit}'\n";

        $replacements = [
            '{{APP_NAME}}'  => $appSlug,
            '{{APP_ID}}'    => $app->id,
            '{{DOMAIN}}'    => $domain,
            '{{APP_PORT}}'  => $containerPort,
            '{{IMAGE_NAME}}' => $imageName,
            '{{VOLUMES}}'   => $volumeYaml,
            '{{VOLUME_DEFINITIONS}}' => $volumeDefinitions,
            '{{RESOURCES}}' => $resourcesYaml,
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
