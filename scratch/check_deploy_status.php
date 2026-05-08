<?php

require 'vendor/autoload.php';
$app_boot = require_once 'bootstrap/app.php';

use App\Models\Application;
use App\Models\Deployment;
use App\Services\Deployment\SSHService;

// Statut du dernier déploiement
$dep = Deployment::where('application_id', function($q) {
    $q->select('id')->from('applications')->where('name', 'vpsly-test-app');
})->latest()->first();

echo "=== Deployment Status ===\n";
echo "ID: {$dep->id}\n";
echo "Status: {$dep->status}\n";
echo "Started: {$dep->started_at}\n";
echo "Finished: {$dep->finished_at}\n\n";

// Logs de l'image actuelle sur le VPS
$app = Application::where('name', 'vpsly-test-app')->first();
$ssh = app(SSHService::class);
$ssh->connect($app->server);

echo "=== Current Image ===\n";
echo $ssh->exec("docker inspect vpsly-test-app --format '{{.Config.Image}}'") . "\n";

echo "=== Last PHP Error (stderr) ===\n";
$raw = $ssh->exec("docker logs vpsly-test-app --tail 30 2>&1 | grep -i 'error\|exception\|fatal\|warning' | tail -20");
echo ($raw ?: "(rien trouvé)") . "\n";
