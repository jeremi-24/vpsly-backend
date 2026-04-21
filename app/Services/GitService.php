<?php

namespace App\Services;

use Exception;

class GitService
{
    public function __construct(protected SshService $ssh) {}

    public function clone(string $repo, string $branch, string $path, ?string $token = null): void
    {
        // 1. Validation de sécurité stricte pour éviter rm -rf /
        if (!str_starts_with($path, '/var/www/vpsly/apps/')) {
            throw new Exception("Path de déploiement invalide et dangereux : {$path}");
        }

        // 2. Nettoyage sécurisé
        $this->ssh->exec("rm -rf {$path}");
        
        // 3. Clone (On injecte le token OAuth si présent pour la simplicité)
        $cloneUrl = $repo;
        if ($token) {
            $cloneUrl = str_replace('https://', "https://{$token}@ ", $repo);
        }

        $this->ssh->exec("git clone -b {$branch} {$cloneUrl} {$path}");
    }

    public function pull(string $branch, string $path): void
    {
        if (!str_starts_with($path, '/var/www/vpsly/apps/')) {
            throw new Exception("Path de déploiement invalide : {$path}");
        }
        
        $this->ssh->exec("cd {$path} && git pull origin {$branch}");
    }
}
