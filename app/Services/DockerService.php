<?php

namespace App\Services;

use App\Models\Application;
use Exception;
use Illuminate\Support\Facades\File;

class DockerService
{
    public function __construct(protected SshService $ssh) {}

    /**
     * Détecte la stack du projet en scrutant les fichiers sources du clone.
     */
    public function detectStack(string $path): string
    {
        // On vérifie l'existence de fichiers clés métier
        $isLaravel = trim($this->ssh->exec("test -f {$path}/artisan && echo 'yes' || echo 'no'")) === 'yes';
        if ($isLaravel) return 'laravel';

        $isNode = trim($this->ssh->exec("test -f {$path}/package.json && echo 'yes' || echo 'no'")) === 'yes';
        if ($isNode) return 'node';

        return 'static';
    }

    /**
     * Compile le docker-compose depuis le système de Stubs (Templates purs)
     */
    public function generateCompose(Application $app, string $stack): string
    {
        $stubPath = resource_path("stacks/{$stack}/docker-compose.yml.stub");
        
        if (!File::exists($stubPath)) {
            // Fallback sur un statique/générique
            $stubPath = resource_path("stacks/static/docker-compose.yml.stub");
        }

        $content = File::get($stubPath);
        
        $domain = "{$app->name}.sslip.io"; // Plus tard on récupérera les domaines réels de la DB

        // Replace basique, pas de moteur de template lourd
        $content = str_replace('{{APP_NAME}}', $app->name, $content);
        $content = str_replace('{{DOMAIN}}', $domain, $content);

        return $content;
    }

    public function writeCompose(string $path, string $content): void
    {
        // Écriture via heredoc (meilleur que base64 et supporté nativement)
        // On échappe les $ pour empêcher l'interpolation par bash
        $escapedContent = str_replace('$', '\$', $content);
        
        $command = "cat << 'EOF_VPSLY' > {$path}/docker-compose.yml\n{$escapedContent}\nEOF_VPSLY";
        
        $this->ssh->exec($command);
    }

    public function ensureNetworkExists(): void
    {
        // Création idempotente pure (pas de catch hasardeux)
        $this->ssh->exec("docker network ls | grep -q 'vpsly_network' || docker network create vpsly_network");
    }

    public function up(string $path, callable $onLine): void
    {
        $this->ensureNetworkExists();

        $this->ssh->stream("cd {$path} && docker compose up -d --build", $onLine);
    }
}
