<?php

namespace App\Services\Deployment;

use Exception;
use Illuminate\Support\Facades\Log;


class GitService
{
    public function __construct(protected SSHService $ssh) {}

    /**
     * Clone ou Pull selon l'état du dossier sur le serveur.
     */
    public function sync(string $repo, string $branch, string $path, ?string $token = null): void
    {
        try {
            Log::info("[Git] Starting sync", ['repo' => $repo, 'branch' => $branch, 'path' => $path]);
            $this->validatePath($path);

            // On vérifie si c'est déjà un dépôt Git valide
            Log::info("[Git] Checking if .git exists in {$path}...");
            $gitDir = rtrim($path, '/') . '/.git';
            
            // FIX 1: trim() pour éviter les espaces/newlines dans la réponse SSH
            $exists = trim($this->ssh->exec("[ -d \"{$gitDir}\" ] && echo \"yes\" || echo \"no\"")) === 'yes';

            if ($exists) {
                Log::info("[Git] Repository already exists. Pulling...");
                $this->pull($branch, $path);
            } else {
                Log::info("[Git] Repository does not exist or is empty. Cleaning and cloning...");
                // On nettoie le dossier pour éviter l'erreur "destination path already exists and is not an empty directory"
                $this->ssh->exec("rm -rf {$path} && mkdir -p {$path}");
                $this->clone($repo, $branch, $path, $token);
            }
        } catch (Exception $e) {
            Log::error("[Git] SYNC FAILED: " . $e->getMessage(), [
                'repo' => $repo,
                'path' => $path,
                'exception' => get_class($e),
                'trace' => substr($e->getTraceAsString(), 0, 500)
            ]);
            throw $e;
        }
    }




    protected function clone(string $repo, string $branch, string $path, ?string $token = null): void
    {
        $cloneUrl = $repo;
        if ($token) {
            // FIX 4: preg_replace sécurisé pour le token
            $cloneUrl = preg_replace('#https://#', "https://x-access-token:{$token}@", $repo, 1);
        }

        Log::info("[Git] Executing clone command...");
        
        // FIX 2: Escaping dirname
        $parentDir = dirname($path);
        $this->ssh->exec("mkdir -p " . escapeshellarg($parentDir));
        
        $this->ssh->exec("git clone -b " . escapeshellarg($branch) . " " . escapeshellarg($cloneUrl) . " " . escapeshellarg($path));
        Log::info("[Git] Clone command successful.");
    }



    protected function pull(string $branch, string $path): void
    {
        Log::info("[Git] Executing fetch, hard reset and clean...");
        $ePath = escapeshellarg($path);
        
        // FIX 3: Pas de quotes sur le branch name dans origin/branch
        $branchClean = trim($branch);
        
        $this->ssh->exec("cd {$ePath} && git fetch origin && git reset --hard origin/{$branchClean} && git clean -fd");
        Log::info("[Git] Pull command successful.");
    }



    protected function validatePath(string $path): void
    {
        if (!str_starts_with($path, '/var/www/vpsly/')) {
            throw new Exception("Chemin de déploiement non autorisé : {$path}");
        }
    }
}
