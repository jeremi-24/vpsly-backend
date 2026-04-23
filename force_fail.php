<?php

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Application;
use App\Models\Deployment;
use App\Enums\DeploymentStatus;

echo "--- Reset des applications en cours ---\n";
$apps = Application::where('is_deploying', true)->get();
foreach ($apps as $app) {
    $app->update([
        'is_deploying' => false,
        'status' => 'failed'
    ]);
    
    // On notifie le frontend via Reverb
    $deploymentId = Deployment::where('application_id', $app->id)->latest()->first()?->id;
    if ($deploymentId) {
        event(new \App\Events\DeploymentStatusUpdatedEvent(
            $deploymentId,
            $app->id,
            'failed',
            false
        ));
    }
    echo "App {$app->name} reset envoyée.\n";
}

echo "--- Reset des déploiements bloqués ---\n";
$deploymentsUpdated = Deployment::whereIn('status', ['pending', 'preparing', 'cloning', 'building', 'deploying'])
    ->update([
        'status' => 'failed',
        'finished_at' => now()
    ]);
echo "$deploymentsUpdated déploiements mis en échec.\n";
