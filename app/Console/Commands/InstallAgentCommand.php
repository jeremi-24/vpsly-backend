<?php

namespace App\Console\Commands;

use App\Models\Server;
use App\Services\SshService;
use Illuminate\Console\Command;

class InstallAgentCommand extends Command
{
    protected $signature = 'vpsly:agent-install {server_id}';
    protected $description = 'Installe l\'agent Go de monitoring sur le serveur distant (Compilation VPS robuste).';

    public function handle(SshService $ssh)
    {
        $serverId = $this->argument('server_id');
        $server = Server::findOrFail($serverId);

        $this->info("Connexion au serveur {$server->name} ({$server->ip})...");

        try {
            $ssh->connect($server);

            // 1. Vérification si déjà installé
            $this->comment("Vérification de l'agent existant...");
            $currentAgent = trim($ssh->exec("vpsly-agent stats", false));
            if (str_contains($currentAgent, 'cpu_usage')) {
                $this->info("L'agent est déjà installé et fonctionnel. Passage à l'étape suivante.");
                return;
            }

            // 2. Vérification / Installation de Go
            $this->comment("Vérification de Go...");
            $goPath = trim($ssh->exec("which go", false));
            if (empty($goPath)) {
                $this->warn("Go n'est pas installé. Installation en cours (peut prendre 1-2 min)...");
                $ssh->exec("apt update && apt install -y golang-go"); // golang-go est plus standard sur Ubuntu
            }

            // 2. Nettoyage et Préparation
            $this->comment("Préparation du dossier de build...");
            $ssh->exec("rm -rf /tmp/vpsly-agent-install");
            $ssh->exec("mkdir -p /tmp/vpsly-agent-install");

            // 3. Envoi du code source (main.go)
            $this->comment("Envoi du code source...");
            $goCode = file_get_contents(base_path('../vpsly-agent/main.go'));
            // On utilise base64 pour éviter les problèmes de caractères spéciaux/heredoc
            $encodedCode = base64_encode($goCode);
            $ssh->exec("echo '{$encodedCode}' | base64 -d > /tmp/vpsly-agent-install/main.go");

            // 4. Création du go.mod robuste (versions fixées)
            $this->comment("Création du fichier go.mod sécurisé...");
            $goMod = <<<EOF
module vpsly-agent

go 1.18

require (
    github.com/docker/docker v24.0.7+incompatible
    github.com/shirou/gopsutil/v3 v3.24.5
)

require (
    github.com/Microsoft/go-winio v0.6.1 // indirect
    github.com/docker/distribution v2.8.2+incompatible // indirect
    github.com/docker/go-connections v0.4.0 // indirect
    github.com/docker/go-units v0.5.0 // indirect
    github.com/gogo/protobuf v1.3.2 // indirect
    github.com/lufia/plan9stats v0.0.0-20211012122336-39d0f177ccd0 // indirect
    github.com/moby/term v0.5.0 // indirect
    github.com/morikuni/aec v1.0.0 // indirect
    github.com/opencontainers/go-digest v1.0.0 // indirect
    github.com/opencontainers/image-spec v1.0.2 // indirect
    github.com/pkg/errors v0.9.1 // indirect
    github.com/power-devops/perfstat v0.0.0-20210106213030-5aafc221ea8c // indirect
    github.com/shoenig/go-m1cpu v0.1.6 // indirect
    github.com/tklauser/go-sysconf v0.3.12 // indirect
    github.com/tklauser/numcpus v0.6.1 // indirect
    github.com/yusufpapurcu/wmi v1.2.4 // indirect
    golang.org/x/mod v0.8.0 // indirect
    golang.org/x/net v0.6.0 // indirect
    golang.org/x/sys v0.20.0 // indirect
    golang.org/x/time v0.3.0 // indirect
    golang.org/x/tools v0.6.0 // indirect
    gotest.tools/v3 v3.5.1 // indirect
)
EOF;
            $encodedMod = base64_encode($goMod);
            $ssh->exec("echo '{$encodedMod}' | base64 -d > /tmp/vpsly-agent-install/go.mod");

            // 5. Compilation
            $this->comment("Compilation de l'agent sur le VPS...");
            $ssh->exec("cd /tmp/vpsly-agent-install && go mod tidy && go build -o vpsly-agent main.go");

            // 6. Installation
            $this->comment("Installation du binaire...");
            $ssh->exec("mv /tmp/vpsly-agent-install/vpsly-agent /usr/local/bin/vpsly-agent");
            $ssh->exec("chmod +x /usr/local/bin/vpsly-agent");
            $ssh->exec("rm -rf /tmp/vpsly-agent-install");

            // 7. Test
            $this->comment("Test de l'agent...");
            $test = $ssh->exec("vpsly-agent stats");
            
            if (str_contains($test, 'cpu_usage')) {
                $this->success("L'agent vpsly-agent a été installé et compilé avec succès sur {$server->name} !");
            } else {
                $this->error("L'agent semble installé mais le test a échoué : " . $test);
            }

        } catch (\Exception $e) {
            $this->error("Erreur lors de l'installation : " . $e->getMessage());
        }
    }

    private function success($message)
    {
        $this->output->writeln("<info>{$message}</info>");
    }
}
