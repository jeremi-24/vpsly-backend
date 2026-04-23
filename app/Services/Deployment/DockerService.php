<?php

namespace App\Services\Deployment;

use App\Models\Deployment;
use App\Models\Server;
use App\Enums\LogType;

class DockerService
{
    public function __construct(
        protected SSHService $ssh,
        protected LogStreamer $logStreamer
    ) {
    }

    /**
     * S'assure que Docker et Docker Compose sont installés et fonctionnels sur le VPS.
     * Cette méthode est idempotente.
     */
    public function ensureInstalled(Server $server, Deployment $deployment): void
    {
        $this->logStreamer->log($deployment, "🔍 Vérification de Docker sur le serveur...");

        $check = $this->ssh->exec("command -v docker || echo 'not found'");

        if (str_contains($check, 'not found')) {
            $this->logStreamer->log($deployment, "⚠️ Docker non trouvé. Installation automatique (PaaS Mode)...", LogType::INFO);

            try {
                // Téléchargement et exécution du script officiel Docker (idempotent et multi-distro)
                $this->ssh->exec("curl -fsSL https://get.docker.com -o get-docker.sh");
                $this->ssh->exec("sh get-docker.sh");
                $this->ssh->exec("rm get-docker.sh");

                // Activation et démarrage du service
                $this->ssh->exec("systemctl enable --now docker || true");

                // Ajout de l'utilisateur au groupe docker pour éviter d'utiliser sudo à chaque fois
                $this->ssh->exec("usermod -aG docker {$server->ssh_user} || true");

                $this->logStreamer->log($deployment, " Docker installé et configuré avec succès.", LogType::SUCCESS);
            } catch (\Exception $e) {
                throw new \Exception("Échec de l'installation automatique de Docker : " . $e->getMessage());
            }
        } else {
            // Docker est là, on vérifie s'il est démarré
            $status = $this->ssh->exec("systemctl is-active docker || echo 'inactive'");
            if (str_contains($status, 'inactive')) {
                $this->logStreamer->log($deployment, "⚠️ Docker est arrêté. Redémarrage...", LogType::INFO);
                $this->ssh->exec("systemctl start docker");
            }
            $this->logStreamer->log($deployment, " Docker est opérationnel sur le serveur.", LogType::SUCCESS);
        }

        // Vérification finale de Docker Compose (V2 est un plugin docker maintenant)
        $composeCheck = $this->ssh->exec("docker compose version || echo 'not found'");
        if (str_contains($composeCheck, 'not found')) {
            $this->logStreamer->log($deployment, "⚠️ Docker Compose V2 manquant. Installation du plugin...", LogType::INFO);
            // Tentative d'installation via le gestionnaire de paquets (Debian/Ubuntu fallback)
            $this->ssh->exec("apt-get update && apt-get install -y docker-compose-plugin || true");

            // Re-vérification
            $finalCheck = $this->ssh->exec("docker compose version || echo 'failed'");
            if (str_contains($finalCheck, 'failed')) {
                throw new \Exception("L'infrastructure nécessite Docker Compose V2 (docker compose).");
            }
        }
    }
}
