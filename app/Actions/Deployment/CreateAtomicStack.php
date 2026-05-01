<?php

namespace App\Actions\Deployment;

use App\Models\Application;
use App\Models\StandaloneDatabase;
use App\Models\Server;
use App\Services\GitHubService;
use App\Services\Deployment\PresetService;
use Illuminate\Support\Str;

class CreateAtomicStack
{
    public function __construct(
        protected PresetService $presets,
        protected GitHubService $github
    ) {}

    /**
     * Crée une stack complète (App + DB + ENV) en une seule fois.
     */
    public function execute(array $data): array
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
            'user_id' => $data['user_id'],
            'status' => 'preparing',
            'build_pack' => "nixpacks:{$preset}",
            'is_deploying' => true,
        ]);

        // 1.5. Tentative de création du Webhook GitHub (Zéro Config)
        try {
            $user = \App\Models\User::find($data['user_id']);
            if ($user && $user->github_token) {
                // Extraction owner/repo de l'URL (ex: https://github.com/owner/repo)
                $urlPath = parse_url($data['repo_url'], PHP_URL_PATH);
                $parts = explode('/', trim($urlPath, '/'));
                
                if (count($parts) >= 2) {
                    $owner = $parts[0];
                    $repo = $parts[1];
                    $repo = str_replace('.git', '', $repo);

                    $callbackUrl = config('app.url') . '/api/webhooks/github';
                    
                    $hookId = $this->github->createWebhook($user, $owner, $repo, $callbackUrl);
                    
                    $app->update(['github_hook_id' => $hookId]);
                }
            }
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::warning("Impossible de créer le webhook GitHub : " . $e->getMessage());
            // On ne bloque pas la création de l'app si le webhook échoue
        }

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
            $dbType = $presetConfig['database'] === 'mysql' ? 'mysql' : 'postgres';

            $db = StandaloneDatabase::create([
                'name' => "DB for {$app->name}",
                'uuid' => (string) Str::uuid(),
                'type' => $dbType,
                'server_id' => $server->id,
                'application_id' => $app->id,
                'image' => $dbType === 'mysql' ? 'mysql:8' : 'postgres:15',
                'db_name' => $dbName,
                'db_user' => $dbUser,
                'db_password' => $dbPass,
                'status' => 'preparing',
            ]);
            
            // Injection automatique des ENV de connexion
            $this->presets->linkDatabase($app, $db, $preset);

            // CRITIQUE : Lancer physiquement le déploiement de la base sur le serveur !
            \App\Jobs\DeployDatabaseJob::dispatch($db);
        }

        // 4. Création du déploiement initial
        $deployment = \App\Models\Deployment::create([
            'application_id' => $app->id,
            'status' => 'pending',
        ]);

        // 5. Déclenchement du job de déploiement
        \App\Jobs\DeployApplicationJob::dispatch($deployment->id);

        return [
            'application' => $app,
            'deployment' => $deployment
        ];
    }
}
