<?php

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Deployment;
use App\Models\DeploymentLog;

// Récupérer le tout dernier déploiement
$deployment = Deployment::latest()->first();

if (!$deployment) {
    echo "Aucun déploiement trouvé.\n";
    exit;
}

echo "--- Dernier déploiement ID: {$deployment->id} (App: {$deployment->application->name}) ---\n";
echo "Status: {$deployment->status}\n";

$logs = DeploymentLog::where('deployment_id', $deployment->id)
    ->orderBy('id', 'desc')
    ->limit(50)
    ->get()
    ->reverse();

foreach ($logs as $log) {
    echo "[{$log->type}] {$log->message}\n";
}
