<?php

namespace App\Actions\Deployment;

use App\Models\Application;
use App\Models\StandalonePostgresql;
use App\Models\Server;
use App\Services\Deployment\PresetService;
use Illuminate\Support\Str;

class CreateAtomicStack
{
    public function __construct(protected PresetService $presets) {}

    /**
     * Crée une stack complète (App + DB + ENV) en une seule fois.
     */
    public function execute(array $data): Application
    {
        $server = Server::findOrFail($data['server_id']);
        $preset = $data['preset'] ?? 'generic';
        $presetConfig = $this->presets->getConfiguration($preset);

        // 1. Création de l'Application
        $app = Application::create([
            'name' => $data['name'],
            'repo_url' => $data['repo_url'],
            'branch' => $data['branch'] ?? 'main',
            'server_id' => $server->id,
            'user_id' => auth()->id(),
            'status' => 'preparing',
            'build_pack' => 'nixpacks',
        ]);

        // 2. Injection des variables du Preset
        foreach ($presetConfig['env'] as $key => $value) {
            $app->environmentVariables()->create([
                'key' => $key,
                'value' => $value,
                'is_runtime' => true,
            ]);
        }

        // 3. Création et Liaison de la Database (si demandée)
        if (isset($presetConfig['database'])) {
            $dbName = Str::slug($app->name) . '_db';
            $dbUser = 'vpsly_user_' . Str::random(4);
            $dbPass = Str::random(16);

            $db = StandalonePostgresql::create([
                'name' => "DB for {$app->name}",
                'uuid' => (string) Str::uuid(),
                'server_id' => $server->id,
                'application_id' => $app->id,
                'image' => $presetConfig['database'] === 'mysql' ? 'mysql:8' : 'postgres:15',
                'postgres_db' => $dbName,
                'postgres_user' => $dbUser,
                'postgres_password' => $dbPass,
                'status' => 'preparing',
            ]);
            
            // Injection automatique des ENV de connexion
            $this->presets->linkDatabase($app, $db, $preset);
        }

        return $app;
    }
}
