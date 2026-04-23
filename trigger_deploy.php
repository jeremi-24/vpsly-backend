<?php

use App\Models\Application;
use App\Models\Deployment;
use App\Jobs\DeployApplicationJob;

$appId = 1;
$application = Application::findOrFail($appId);

// Création minimaliste basée sur la migration réelle
$deployment = Deployment::create([
    'application_id' => $application->id,
    'status' => 'pending'
]);

DeployApplicationJob::dispatch($deployment->id);

echo "Déploiement #{$deployment->id} dispatché pour l'application {$application->name}!\n";
