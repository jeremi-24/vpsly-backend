<?php

namespace App\Services;

use App\Models\Deployment;
use App\Models\DeploymentLog;

class DeploymentLoggerService
{
    public function log(Deployment $deployment, string $line): void
    {
        // 1. Sauvegarde en Base de Données
        DeploymentLog::create([
            'deployment_id' => $deployment->id,
            'line' => $line,
            'team_id' => $deployment->team_id,
        ]);

        // 2. Dispatch de l'évènement Reverb pour le terminal auto-scroll en live
        try {
            event(new \App\Events\DeploymentLogEvent($deployment->id, $line));
        } catch (\Exception $e) {
            // On ignore silencieusement si le WebSocket est tombé (résilience du déploiement)
        }
    }
}
