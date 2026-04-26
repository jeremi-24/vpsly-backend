<?php
use App\Models\Application;
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$appModel = Application::where('name', 'vpsly-test-app3')->first();
if (!$appModel) {
    echo "Application NOT FOUND\n";
    exit;
}

$dep = $appModel->deployments()->latest()->first();
echo "APP_ID=" . $appModel->id . "\n";
if ($dep) {
    echo "DEP_ID=" . $dep->id . "\n";
    echo "DEP_STATUS=" . $dep->status . "\n";
    echo "LOGS_COUNT=" . count($dep->logs ?? []) . "\n";
    $allLogs = $dep->logs ?? [];
    echo "LAST_20_LOGS=" . json_encode(array_slice($allLogs, -20), JSON_PRETTY_PRINT) . "\n";
    $errors = array_filter($allLogs, fn($l) => ($l['type'] ?? '') === 'error');
    if ($errors) {
        echo "ERRORS_FOUND=" . json_encode($errors, JSON_PRETTY_PRINT) . "\n";
    }
} else {
    echo "NO DEPLOYMENT FOUND\n";
}
