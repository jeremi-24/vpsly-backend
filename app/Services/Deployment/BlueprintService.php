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
        // 1. Création du dossier de travail (Workdir)
        $this->ssh->exec("mkdir -p \"{$appPath}\"");

        // 2. Génération du fichier .env (Variables Système + Utilisateur)
        $this->syncEnvironmentVariables($app, $appPath, $nixpacksPlan);

        // 3. Docker Compose (utilise l'image déjà buildée par Nixpacks)
        $compose = $this->getCompose($app, $imageName, $nixpacksPlan);
        $this->writeRemoteFile($appPath . '/docker-compose.yml', $compose);
    }

    /**
     * Génère et écrit le fichier .env de manière atomique sur le VPS.
     * Copie de la logique de Coolify pour les variables magiques.
     */
    protected function syncEnvironmentVariables(Application $app, string $appPath, array $nixpacksPlan): void
    {
        $appSlug = $this->getSlug($app);
        $isPhp = $this->isPhp($nixpacksPlan);
        
        $serverIp = $app->server->ip ?? '127.0.0.1';
        $domain = "{$appSlug}.{$serverIp}.sslip.io";

        $envVars = [
            'APP_NAME' => $appSlug,
            'APP_ENV' => 'production',
            'APP_URL' => "https://{$domain}",
        ];

        if ($isPhp) {
            // Logique Laravel/PHP
            $envVars['NIXPACKS_PHP_ROOT_DIR'] = '/app/public';
            $envVars['NIXPACKS_PHP_FALLBACK_PATH'] = '/index.php';
            
            // Génération automatique de APP_KEY si absente
            if (!$app->environmentVariables()->where('key', 'APP_KEY')->exists()) {
                $envVars['APP_KEY'] = 'base64:' . base64_encode(random_bytes(32));
            }
        } else {
            // Logique Node.js (NestJS, etc.)
            $envVars['NODE_ENV'] = 'production';
            $envVars['PORT'] = '3000';
        }

        // Injection des bases de données liées
        $databases = $app->databases()->with('server')->get();
        $dbTypesFound = [];
        
        foreach ($databases as $db) {
            $type = str_contains(strtolower($db->image), 'mysql') ? 'mysql' : 
                   (str_contains(strtolower($db->image), 'redis') ? 'redis' : 'postgres');

            $dbTypesFound[$type] = ($dbTypesFound[$type] ?? 0) + 1;
            
            // Si sur le même serveur, on utilise l'alias Docker, sinon l'IP
            $isSameServer = $db->server_id === $app->server_id;
            $host = $isSameServer ? $db->uuid : $db->server->ip;
            $port = $type === 'mysql' ? '3306' : ($type === 'redis' ? '6379' : '5432');
            
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
                // Mapping DB spécifique (Coolify style)
                $laravelType = ($type === 'postgres') ? 'pgsql' : $type;

                $envVars["{$prefix}DB_CONNECTION"] = $laravelType;
                $envVars["{$prefix}DB_HOST"] = $host;
                $envVars["{$prefix}DB_PORT"] = $port;
                $envVars["{$prefix}DB_DATABASE"] = $db->postgres_db;
                $envVars["{$prefix}DB_USERNAME"] = $db->postgres_user;
                $envVars["{$prefix}DB_PASSWORD"] = $db->postgres_password;
                
                $auth = "{$db->postgres_user}:{$db->postgres_password}";
                $envVars["{$prefix}DATABASE_URL"] = "{$type}://{$auth}@{$host}:{$port}/{$db->postgres_db}";
            }
        }

        // Variables utilisateur (Priorité MAX)
        $userVars = $app->environmentVariables()->get();
        foreach ($userVars as $var) {
            $envVars[$var->key] = $var->value;
        }

        $content = "";
        foreach ($envVars as $key => $value) {
            $content .= "{$key}=\"{$value}\"\n";
        }

        $this->writeAtomicRemoteFile($appPath . '/.env', $content);
    }

    /**
     * Génère le docker-compose.yml final (Moteur de combo - 100% Coolify Style).
     */
    protected function getCompose(Application $app, string $imageName, array $nixpacksPlan): string
    {
        $appSlug = $this->getSlug($app);
        $isPhp = $this->isPhp($nixpacksPlan);
        $containerPort = $isPhp ? 80 : 3000;
        
        $serverIp = $app->server->ip ?? '127.0.0.1';
        $domain = "{$appSlug}.{$serverIp}.sslip.io";

        // Limites de ressources (Copy of Coolify)
        $memoryLimit = "512MB";
        $cpuLimit = "0.5";

        $services = [
            $appSlug => [
                'container_name' => $appSlug,
                'image' => $imageName,
                'restart' => 'always',
                'env_file' => ['.env'],
                'networks' => ['vpsly'],
                'labels' => [
                    "traefik.enable=true",
                    "traefik.http.routers.{$appSlug}.rule=Host(`{$domain}`)",
                    "traefik.http.routers.{$appSlug}.entrypoints=web",
                    "traefik.http.services.{$appSlug}.loadbalancer.server.port={$containerPort}",
                ],
                'healthcheck' => [
                    'test' => ["CMD-SHELL", "curl -f http://localhost:{$containerPort}/ || exit 1"],
                    'interval' => '10s',
                    'timeout' => '5s',
                    'retries' => 3,
                    'start_period' => '10s'
                ],
                'deploy' => [
                    'resources' => [
                        'limits' => [
                            'memory' => $memoryLimit,
                            'cpus' => $cpuLimit
                        ]
                    ]
                ]
            ]
        ];

        // Intégration des volumes persistants
        $volumes = $app->persistentVolumes()->get();
        if ($volumes->count() > 0) {
            $services[$appSlug]['volumes'] = [];
            foreach ($volumes as $vol) {
                $source = $vol->host_path ?: $vol->name;
                $services[$appSlug]['volumes'][] = "{$source}:{$vol->mount_path}";
            }
        }

        // Configuration Réseau
        $compose = [
            'services' => $services,
            'networks' => [
                'vpsly' => [
                    'external' => true,
                    'name' => 'vpsly'
                ]
            ]
        ];

        return \Symfony\Component\Yaml\Yaml::dump($compose, 10);
    }


    protected function writeRemoteFile(string $filePath, string $content): void
    {
        $base64 = base64_encode($content);
        $command = "echo '{$base64}' | base64 -d > \"{$filePath}\"";
        $this->ssh->exec($command);
    }

    protected function writeAtomicRemoteFile(string $filePath, string $content): void
    {
        $base64 = base64_encode($content);
        $tmpPath = $filePath . '.tmp';
        $command = "echo '{$base64}' | base64 -d > \"{$tmpPath}\" && chmod 600 \"{$tmpPath}\" && mv \"{$tmpPath}\" \"{$filePath}\"";
        $this->ssh->exec($command);
    }

    public function getSlug(Application $app): string
    {
        return strtolower(preg_replace('/[^a-z0-9\-]/', '-', $app->name));
    }

    public function isPhp(array $plan): bool
    {
        return $this->planContains($plan, 'php');
    }

    protected function planContains(array $plan, string $keyword): bool
    {
        $jsonStr = strtolower(json_encode($plan));
        return str_contains($jsonStr, $keyword);
    }
}
