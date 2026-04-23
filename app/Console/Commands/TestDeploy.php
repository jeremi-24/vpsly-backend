<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Models\Server;
use App\Models\Application;
use App\Models\Deployment;
use App\Models\DeploymentLog;
use App\Jobs\DeployApplicationJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class TestDeploy extends Command
{
    // On permet de choisir la clé ssh (id_rsa ou id_ed25519)
    protected $signature = 'test:deploy {key=id_rsa}';
    protected $description = 'Test global du pipeline de déploiement (Option 1)';

    public function handle()
    {
        $this->info("1. Préparation de la base de test...");

        // Désactivation temporaire des foreign keys pour SQLite
        Schema::disableForeignKeyConstraints();
        DeploymentLog::truncate();
        Deployment::truncate();
        Application::truncate();
        Server::truncate();
        User::truncate();
        Schema::enableForeignKeyConstraints();

        $user = User::create([
            'name' => 'Tester',
            'email' => 'test@deploy.local',
            'password' => bcrypt('password')
        ]);

        $keyPath = 'C:\\Users\\pc\\.ssh\\' . $this->argument('key');
        if (!file_exists($keyPath)) {
            $this->error("Clé SSH introuvable : " . $keyPath);
            $this->warn("Avez-vous bien une de ces clés disponibles ? (.ssh/id_rsa ou .ssh/id_ed25519)");
            return;
        }

        $privateKey = file_get_contents($keyPath);

        $server = Server::create([
            'user_id' => $user->id,
            'name' => 'VPS Test',
            'ip' => '62.238.12.74',
            'ssh_user' => 'root',
            'ssh_port' => 22,
            'ssh_private_key' => $privateKey,
        ]);

        $this->info("2. App cible créée : docker/getting-started-app");
        $app = Application::create([
            'user_id' => $user->id,
            'server_id' => $server->id,
            'name' => 'vpsly-demo',
            'repo_url' => 'https://github.com/docker/getting-started-app.git',
            'branch' => 'main',
        ]);

        $deployment = Deployment::create([
            'application_id' => $app->id,
            'status' => 'pending',
        ]);

        $this->info("3. Exécution synchrone du Job de déploiement (DeployApplicationJob)\n");

        try {
            DeployApplicationJob::dispatchSync($deployment->id);
        } catch (\Exception $e) {
            $this->error("\nErreur Fatale interceptée : " . $e->getMessage());
        }

        // Récupération de l'état final
        $deployment->refresh();
        if ($deployment->status === 'success') {
            $this->info("\n Résultat : " . strtoupper($deployment->status));
        } else {
            $this->error("\n❌ Résultat : " . strtoupper($deployment->status));
        }

        $this->info("\n--- LOGS SAUVEGARDÉS (State Machine MVP) ---");
        foreach ($deployment->logs as $log) {
            echo "[Log {$log->created_at}] {$log->line}\n";
        }
    }
}
