<?php

use App\Models\Deployment;
use App\Models\DeploymentLog;

// Récupérer le TOUT DERNIER déploiement
$deployment = Deployment::orderBy('id', 'desc')->first();

if (!$deployment) {
    echo "Aucun déploiement trouvé.\n";
    exit;
}

echo "--- LOGS POUR LE DÉPLOIEMENT #{$deployment->id} ({$deployment->status}) ---\n";

$logs = DeploymentLog::where('deployment_id', $deployment->id)
    ->orderBy('id', 'asc')
    ->get();

foreach ($logs as $log) {
    if (trim($log->line) !== "") {
        echo "[" . $log->type . "] " . $log->line . PHP_EOL;
    }
}
