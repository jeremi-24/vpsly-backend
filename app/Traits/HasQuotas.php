<?php

namespace App\Traits;

use Illuminate\Support\Facades\Config;

trait HasQuotas
{
    /**
     * Récupère la configuration du plan actuel de l'équipe.
     */
    public function getPlanConfig(): array
    {
        $plan = $this->plan ?? 'starter';
        return Config::get("vpsly.plans.{$plan}", Config::get("vpsly.plans.starter"));
    }

    /**
     * Vérifie si l'équipe a accès à une fonctionnalité spécifique.
     */
    public function hasFeature(string $feature): bool
    {
        $config = $this->getPlanConfig();
        return data_get($config, "features.{$feature}", false);
    }

    /**
     * Vérifie si l'équipe peut créer une nouvelle application.
     */
    public function canCreateApplication(): bool
    {
        $limit = data_get($this->getPlanConfig(), 'max_apps', 0);
        
        if ($limit === -1) return true; // Illimité
        
        return $this->applications()->count() < $limit;
    }

    /**
     * Vérifie si l'équipe peut ajouter un nouveau serveur.
     */
    public function canAddServer(): bool
    {
        $limit = data_get($this->getPlanConfig(), 'max_servers', 0);
        
        if ($limit === -1) return true;
        
        return $this->servers()->count() < $limit;
    }

    /**
     * Vérifie si l'équipe peut ajouter une base de données.
     */
    public function canCreateDatabase(): bool
    {
        $limit = data_get($this->getPlanConfig(), 'max_databases', 0);
        
        if ($limit === -1) return true;
        
        return $this->databases()->count() < $limit;
    }
}
