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
        $sslipDomain = "{$appSlug}.{$serverIp}.sslip.io";

        // Business Rule: Starter = sslip.io only. Solo/Pro = Custom Domain allowed.
        $plan = $app->team?->plan ?? 'starter';
        $domain = ($plan === 'starter') ? $sslipDomain : ($app->domain ?: $sslipDomain);

        $envVars = [
            'APP_NAME' => $appSlug,
            'APP_ENV' => 'production',
            'APP_URL' => "https://{$domain}",
        ];

        if ($isPhp) {
            // Logique Laravel/PHP
            $envVars['NIXPACKS_PHP_ROOT_DIR'] = '/app/public';
            
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
            $type = $db->type;

            $dbTypesFound[$type] = ($dbTypesFound[$type] ?? 0) + 1;
            
            // Si sur le même serveur, on utilise l'alias Docker, sinon l'IP
            $isSameServer = $db->server_id === $app->server_id;
            $host = $isSameServer ? $db->uuid : $db->server->ip;
            $port = ($type === 'mysql' || $type === 'mariadb') ? '3306' : ($type === 'redis' ? '6379' : '5432');
            
            $isSecondary = $dbTypesFound[$type] > 1;
            $slugName = strtoupper(preg_replace('/[^a-z0-9]/i', '_', $db->name));
            $prefix = $isSecondary ? "{$slugName}_" : "";

            if ($type === 'redis') {
                $envVars["{$prefix}REDIS_HOST"] = $host;
                $envVars["{$prefix}REDIS_PORT"] = $port;
                if ($db->db_password) {
                    $envVars["{$prefix}REDIS_PASSWORD"] = $db->db_password;
                    $envVars["{$prefix}REDIS_URL"] = "redis://:{$db->db_password}@{$host}:{$port}";
                } else {
                    $envVars["{$prefix}REDIS_URL"] = "redis://{$host}:{$port}";
                }
            } else {
                // Mapping DB spécifique (Coolify style)
                $laravelType = ($type === 'postgres') ? 'pgsql' : $type;

                $envVars["{$prefix}DB_CONNECTION"] = $laravelType;
                $envVars["{$prefix}DB_HOST"] = $host;
                $envVars["{$prefix}DB_PORT"] = $port;
                $envVars["{$prefix}DB_DATABASE"] = $db->db_name;
                $envVars["{$prefix}DB_USERNAME"] = $db->db_user;
                $envVars["{$prefix}DB_PASSWORD"] = $db->db_password;
                
                $auth = "{$db->db_user}:{$db->db_password}";
                $envVars["{$prefix}DATABASE_URL"] = "{$type}://{$auth}@{$host}:{$port}/{$db->db_name}";
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
        $sslipDomain = "{$appSlug}.{$serverIp}.sslip.io";
        
        // Business Rule: Starter = sslip.io only. Solo/Pro = Custom Domain allowed.
        $plan = $app->team?->plan ?? 'starter';
        $domain = ($plan === 'starter') ? $sslipDomain : ($app->domain ?: $sslipDomain);

        // Limites de ressources (Ajustées pour petit VPS)
        $cpuLimit = "0.5";
        $memoryReservation = $isPhp ? '128m' : '64m';

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
                    "traefik.http.routers.{$appSlug}.entrypoints=web,websecure",
                    "traefik.http.routers.{$appSlug}.tls=true",
                    "traefik.http.routers.{$appSlug}.tls.certresolver=vpsly",
                    "traefik.http.services.{$appSlug}.loadbalancer.server.port={$containerPort}",
                ],
                'healthcheck' => [
                    'test' => ["CMD-SHELL", "curl -f http://localhost:{$containerPort}/ || exit 1"],
                    'interval' => '10s',
                    'timeout' => '5s',
                    'retries' => 3,
                    'start_period' => '20s'
                ],
                'deploy' => [
                    'resources' => [
                        'limits' => [
                            'cpus' => $cpuLimit
                        ],
                        'reservations' => [
                            'memory' => $memoryReservation
                        ]
                    ]
                ]
            ]
        ];

        // Les bases de données sont gérées de manière indépendante (Standalone)
        // L'application s'y connecte via le réseau Docker 'vpsly' en utilisant l'UUID comme Host.

        // Intégration des volumes persistants pour l'App
        $volumes = $app->persistentVolumes()->get();
        if ($volumes->count() > 0) {
            $services[$appSlug]['volumes'] = [];
            foreach ($volumes as $vol) {
                $source = $vol->host_path ?: $vol->name;
                $services[$appSlug]['volumes'][] = "{$source}:{$vol->mount_path}";
            }
        }

        // Configuration Réseau et Volumes nommés pour la DB
        $compose = [
            'services' => $services,
            'networks' => [
                'vpsly' => [
                    'external' => true,
                    'name' => 'vpsly'
                ]
            ]
        ];

        // Déclaration des volumes nommés de l'application
        foreach ($volumes as $vol) {
            if (!$vol->host_path) {
                $compose['volumes'][$vol->name] = ['driver' => 'local'];
            }
        }

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
