<?php

namespace App\Services;

use App\Models\Application;
use Exception;
use Illuminate\Support\Facades\File;
use App\Services\Deployment\SSHService;

class DockerService
{
    public function __construct(protected SSHService $ssh) {}

    /**
     * Détecte la stack du projet en scrutant les fichiers sources du clone.
     */
    public function detectStack(string $path): string
    {
        // On vérifie l'existence de fichiers clés
        // Laravel
        $isLaravel = trim($this->ssh->exec("test -f {$path}/artisan && echo 'yes' || echo 'no'")) === 'yes';
        if ($isLaravel) return 'laravel';

        // Next.js (souvent package.json contient next)
        $isNext = trim($this->ssh->exec("grep -q '\"next\"' {$path}/package.json 2>/dev/null && echo 'yes' || echo 'no'")) === 'yes';
        if ($isNext) return 'nextjs';

        // Node.js par défaut
        $isNode = trim($this->ssh->exec("test -f {$path}/package.json && echo 'yes' || echo 'no'")) === 'yes';
        if ($isNode) return 'nodejs';

        return 'nodejs'; // Fallback node
    }

    /**
     * Génère le Dockerfile pour la stack spécifiée.
     */
    public function generateDockerfile(string $stack): string
    {
        $stubPath = resource_path("stubs/stacks/{$stack}/Dockerfile");
        
        if (!File::exists($stubPath)) {
            $stubPath = resource_path("stubs/stacks/nodejs/Dockerfile");
        }

        return File::get($stubPath);
    }

    /**
     * Compile le docker-compose depuis le système de Stubs commun.
     */
    public function generateCompose(Application $app, string $stack): string
    {
        $stubPath = resource_path("stubs/stacks/common/docker-compose.yml");
        
        if (!File::exists($stubPath)) {
             throw new Exception("Common docker-compose stub not found.");
        }

        $content = File::get($stubPath);
        
        $domain = $app->domain ?? "{$app->name}.sslip.io";

        // Replace basique pour Traefik et labels
        $content = str_replace('{{APP_NAME}}', strtolower($app->name), $content);
        $content = str_replace('{{DOMAIN}}', $domain, $content);

        return $content;
    }

    public function writeDockerfile(string $path, string $content): void
    {
        $this->writeFile($path . '/Dockerfile', $content);
    }

    public function writeCompose(string $path, string $content): void
    {
        $this->writeFile($path . '/docker-compose.yml', $content);
    }

    protected function writeFile(string $filePath, string $content): void
    {
        $escapedContent = str_replace('$', '\$', $content);
        $command = "cat << 'EOF_VPSLY' > {$filePath}\n{$escapedContent}\nEOF_VPSLY";
        $this->ssh->exec($command);
    }

    public function ensureNetworkExists(): void
    {
        $this->ssh->exec("docker network ls | grep -q 'vpsly_network' || docker network create vpsly_network");
    }

    public function up(string $path, callable $onLine): void
    {
        $this->ensureNetworkExists();
        $this->ssh->stream("cd {$path} && docker compose up -d --build", $onLine);
    }
}
