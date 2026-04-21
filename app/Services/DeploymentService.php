<?php

namespace App\Services;

use App\Models\Application;
use App\Models\Server;
use App\Models\Deployment;
use Exception;

class DeploymentService
{
    public function __construct(
        protected SshService $ssh,
        protected GitService $git,
        protected DockerService $docker,
        protected DeploymentLoggerService $logger
    ) {}

    public function deploy(Application $app, Server $server, Deployment $deployment): void
    {
        // On initialise le logger lié à ce déploiement via un callback pour simplifier
        $log = function(string $line) use ($deployment) {
            $this->logger->log($deployment, $line);
        };

        try {
            $log("Starting deployment flow...");
            
            // 1. SSH Connect
            $log("Connecting to server {$server->ip} via SSH...");
            $this->ssh->connect($server);
            $log("SSH Connection successful.");

            // Conventions
            $appPath = "/var/www/vpsly/apps/{$app->id}";

            // 2. Préparation du dossier
            $log("Preparing application directory: {$appPath}");
            $this->ssh->exec("mkdir -p {$appPath}");

            // 3. Git Clone
            $log("Cloning repository: {$app->repo_url} (branch: {$app->branch})...");
            $this->git->clone($app->repo_url, $app->branch, $appPath, $app->user->github_token);
            $log("Repository cloned successfully.");

            // 4. Generation Docker Compose
            $log("Detecting application stack (resolving template)...");
            $stack = $this->docker->detectStack($appPath);
            $log("Stack detected: " . strtoupper($stack));

            $composeContent = $this->docker->generateCompose($app, $stack);
            $this->docker->writeCompose($appPath, $composeContent);
            $log("docker-compose.yml injected on VPS.");

            // 5. Docker Run & Stream logs
            $log("Building and starting Docker container(s)...");
            $this->docker->up($appPath, function(string $streamLine) use ($log) {
                // Stream direct des logs de docker-compose vers Reverb et DB
                $log($streamLine);
            });

            $log("Docker container started successfully.");
            $log("Deployment completed successfully.");

        } catch (Exception $e) {
            $log("Critical Deployment Error: " . $e->getMessage());
            throw $e; // Rethrow to strictly fail the Job
        }
    }
}
