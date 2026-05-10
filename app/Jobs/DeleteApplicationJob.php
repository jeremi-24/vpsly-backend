<?php

namespace App\Jobs;

use App\Models\Server;
use App\Services\Deployment\DockerService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class DeleteApplicationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;
    public $timeout = 300;

    public function __construct(
        public int $serverId,
        public string $infrastructureType,
        public string $appSlug,
        public ?string $targetPath = null,
        public ?string $domain = null
    ) {}

    public function handle(DockerService $docker, \App\Services\Deployment\SSHService $ssh): void
    {
        $server = Server::find($this->serverId);
        if (!$server) return;

        if ($this->infrastructureType === 'legacy' && !empty($this->targetPath)) {
            // 1. Suppression du processus PM2 si présent (Node/Next apps)
            $ssh->connect($server);
            $ssh->exec("pm2 delete " . escapeshellarg($this->appSlug) . " && pm2 save || true");

            // 2. Suppression de la config Nginx si présente
            $cleanupDomain = $this->domain;
            if (empty($cleanupDomain)) {
                // Reconstruire le domaine sslip.io par défaut si vide
                $server = Server::find($this->serverId);
                if ($server) {
                    $cleanupDomain = "{$this->appSlug}.{$server->ip}.sslip.io";
                }
            }

            if (!empty($cleanupDomain)) {
                $safeName = str_replace('.', '_', $cleanupDomain);
                
                // Liste des patterns possibles pour être sûr de tout supprimer
                $possibleFiles = [
                    "{$safeName}.vpsly.conf", // Nouveau format
                    "{$cleanupDomain}.vpsly.conf", // Format hybride
                    "{$cleanupDomain}" // Ancien format / manuel
                ];

                foreach ($possibleFiles as $file) {
                    $configPath = "/etc/nginx/sites-available/{$file}";
                    $enabledPath = "/etc/nginx/sites-enabled/{$file}";
                    $ssh->exec("sudo rm -f {$enabledPath} {$configPath}");
                }
                
                // Nettoyage optionnel des restes de Certbot si présent
                $ssh->exec("sudo rm -rf /etc/letsencrypt/live/{$cleanupDomain} /etc/letsencrypt/archive/{$cleanupDomain} /etc/letsencrypt/renewal/{$cleanupDomain}.conf || true");
                
                $ssh->exec("sudo systemctl reload nginx");
            }

            // 3. Suppression du dossier pour Legacy
            $ssh->exec("rm -rf " . escapeshellarg($this->targetPath));
            $ssh->disconnect();
        } else {
            // Suppression Docker pour Clean
            $docker->stopAndRemove($server, $this->appSlug);
        }
    }
}
