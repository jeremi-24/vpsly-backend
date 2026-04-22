<?php

namespace App\Services\Deployment;

use App\Models\Application;

class ProxyService
{
    /**
     * Génère les labels Traefik pour une application.
     * Isole la logique de routage du moteur de déploiement.
     */
    public function generateLabels(Application $app, int $containerPort = 80): array
    {
        $domain = $app->domain;
        if (!$domain) {
            return [];
        }

        $safeName = $this->sanitize($app->name);

        return [
            "traefik.enable=true",
            "traefik.http.routers.{$safeName}.rule=Host(`{$domain}`)",
            "traefik.http.routers.{$safeName}.entrypoints=websecure",
            "traefik.http.routers.{$safeName}.tls.certresolver=letsencrypt",
            "traefik.http.services.{$safeName}.loadbalancer.server.port={$containerPort}",
        ];
    }

    protected function sanitize(string $name): string
    {
        return strtolower(preg_replace('/[^a-zA-Z0-9_-]/', '-', $name));
    }
}
