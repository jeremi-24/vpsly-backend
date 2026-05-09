<?php

namespace App\Services\Deployment;

use App\Models\Application;
use App\Models\StandaloneDatabase;
use Illuminate\Support\Str;


class PresetService
{
    /**
     * Retourne la configuration par défaut pour un preset donné.
     */
    public function getConfiguration(string $preset): array
    {
        return match (strtolower($preset)) {
            'laravel' => [
                'env' => [
                    'APP_ENV' => 'production',
                    'APP_DEBUG' => 'false',
                    'LOG_CHANNEL' => 'stderr',
                ],
                'database' => 'mysql',
                'port' => 80,
            ],
            'nestjs', 'nodejs' => [
                'env' => [
                    'NODE_ENV' => 'production',
                    'PORT' => '3000',
                ],
                'database' => 'postgres',
                'port' => 3000,
            ],
            default => [
                'env' => [],
                'database' => null,
                'port' => 3000,
            ],
        };
    }

    /**
     * Injecte les variables de base de données dans l'application.
     */
    public function linkDatabase(Application $app, StandaloneDatabase $db, string $preset): void
    {
        $type = $db->type;
        $dbType = ($type === 'mysql' || $type === 'mariadb') ? 'mysql' : 'postgres';
        
        // Détermination du HOST selon l'infrastructure
        $host = $db->uuid; // Défaut Docker
        if ($app->server->infrastructure_type === 'legacy') {
            $isSameServer = $db->server_id === $app->server_id;
            $host = $isSameServer ? '127.0.0.1' : ($db->server->ip ?? '127.0.0.1');
        }

        $laravelType = ($type === 'postgres') ? 'pgsql' : $type;

        $vars = [
            'DB_CONNECTION' => $laravelType,
            'DB_HOST' => $host,
            'DB_PORT' => $dbType === 'mysql' ? '3306' : '5432',
            'DB_DATABASE' => $db->db_name,
            'DB_USERNAME' => $db->db_user,
            'DB_PASSWORD' => $db->db_password,
        ];

        foreach ($vars as $key => $value) {
            $app->environmentVariables()->updateOrCreate(
                ['key' => strtoupper($key)],
                ['value' => $value, 'is_buildtime' => false, 'is_runtime' => true]
            );
        }

    }

}
