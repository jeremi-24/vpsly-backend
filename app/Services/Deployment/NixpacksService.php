<?php

namespace App\Services\Deployment;

use App\Models\Deployment;
use App\Models\Server;
use Illuminate\Support\Facades\Log;

class NixpacksService
{
    public function __construct(
        protected SSHService $ssh,
        protected LogStreamer $logStreamer
    ) {
    }

    /**
     * S'assure que Nixpacks est installé sur le serveur.
     */
    public function ensureInstalled(Server $server, Deployment $deployment): void
    {
        $this->logStreamer->log($deployment, "🔍 Vérification de Nixpacks sur le serveur...");

        $check = $this->ssh->exec("command -v nixpacks || echo 'not found'");

        if (str_contains($check, 'not found')) {
            $this->logStreamer->log($deployment, "⚠️ Nixpacks non trouvé. Installation en cours...");

            // Installation sécurisée via curl | bash (script officiel)
            $this->ssh->exec("curl -sSL https://nixpacks.com/install.sh | bash");

            // Re-vérification
            $checkAgain = $this->ssh->exec("command -v nixpacks || echo 'failed'");
            if (str_contains($checkAgain, 'failed')) {
                throw new \Exception("L'installation de Nixpacks a échoué sur le serveur.");
            }
            $this->logStreamer->log($deployment, " Nixpacks installé avec succès.");
        } else {
            $this->logStreamer->log($deployment, " Nixpacks est déjà présent sur le serveur.");
        }
    }

    /**
     * Extrait le plan de build Nixpacks au format JSON pour identifier la stack.
     */
    public function getPlan(Server $server, Deployment $deployment, string $appPath, ?string $nodeVersion = null): array
    {
        $this->logStreamer->log($deployment, "🔍 Analyse de la structure du projet via Nixpacks...", \App\Enums\LogType::INFO);

        $env = "";
        if ($nodeVersion) {
            $env = " --env NIXPACKS_NODE_VERSION={$nodeVersion}";
        }

        $command = "cd \"{$appPath}\" && export PATH=\$PATH:/usr/local/bin && nixpacks plan . --format json{$env}";

        try {
            $json = $this->ssh->exec($command);

            if (empty($json)) {
                throw new \Exception("Nixpacks a renvoyé une réponse vide.");
            }

            $plan = json_decode($json, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                // Si le JSON est invalide, on tente de nettoyer (Nixpacks peut parfois sortir du texte avant le JSON)
                if (preg_match('/\{.*\}/s', $json, $matches)) {
                    $plan = json_decode($matches[0], true);
                }

                if (!$plan) {
                    $this->logStreamer->log($deployment, "❌ Erreur JSON Nixpacks. Raw output: " . substr($json, 0, 500), \App\Enums\LogType::ERROR);
                    throw new \Exception("Erreur lors de la lecture du plan Nixpacks : " . json_last_error_msg());
                }
            }

            // Log détaillé pour le debug (visible dans le terminal live)
            $this->logStreamer->log($deployment, "Plan Nixpacks détecté.", \App\Enums\LogType::DEBUG);

            return $plan;
        } catch (\Exception $e) {
            $this->logStreamer->log($deployment, "❌ Erreur d'analyse Nixpacks : " . $e->getMessage(), \App\Enums\LogType::ERROR);
            throw $e;
        }
    }

    /**
     * Build une image Docker à partir du code source en utilisant Nixpacks.
     */
    public function build(Server $server, Deployment $deployment, string $appPath, string $imageName, ?string $nodeVersion = null): void
    {
        $this->logStreamer->log($deployment, " Lancement du build universel (Nixpacks)...", \App\Enums\LogType::INFO);

        $env = "";
        if ($nodeVersion) {
            $env = " --env NIXPACKS_NODE_VERSION={$nodeVersion}";
        }

        // On s'assure que nixpacks est bien dans le PATH pour cette session
        $command = "cd \"{$appPath}\" && export PATH=\$PATH:/usr/local/bin && nixpacks build . --name \"{$imageName}\" --inline-cache{$env}";

        Log::info("[Nixpacks] Running build: {$command}");

        try {
            $this->ssh->stream($command, function ($line) use ($deployment) {
                $this->logStreamer->log($deployment, $line, \App\Enums\LogType::DEBUG);
            });

            $this->logStreamer->log($deployment, "📦 Image Docker buildée avec succès : {$imageName}", \App\Enums\LogType::SUCCESS);
        } catch (\Exception $e) {
            $this->logStreamer->log($deployment, "❌ Échec du build Nixpacks. Vérifiez les erreurs ci-dessus (TypeScript, Build scripts, etc.).", \App\Enums\LogType::ERROR);
            throw $e;
        }
    }


}
