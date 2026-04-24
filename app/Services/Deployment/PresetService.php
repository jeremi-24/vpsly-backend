<?php

namespace App\Services\Deployment;

use App\Models\Application;
use App\Models\StandalonePostgresql;
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
    public function linkDatabase(Application $app, StandalonePostgresql $db, string $preset): void
    {
        $dbType = str_contains(strtolower($db->image), 'mysql') ? 'mysql' : 'postgres';
        $prefix = ($preset === 'laravel' && $dbType === 'postgres') ? 'DB_CONNECTION=pgsql' : "DB_CONNECTION={$dbType}";

        $vars = [
            'DB_HOST' => $db->uuid, // On utilise l'UUID comme hostname Docker
            'DB_PORT' => $dbType === 'mysql' ? '3306' : '5432',
            'DB_DATABASE' => $db->postgres_db,
            'DB_USERNAME' => $db->postgres_user,
            'DB_PASSWORD' => $db->postgres_password,
        ];

        if ($preset === 'laravel') {
            $vars['DB_CONNECTION'] = ($dbType === 'postgres') ? 'pgsql' : 'mysql';
        }

        foreach ($vars as $key => $value) {
            $app->environmentVariables()->updateOrCreate(
                ['key' => $key],
                ['value' => $value, 'is_buildtime' => false, 'is_runtime' => true]
            );
        }
    }
}
