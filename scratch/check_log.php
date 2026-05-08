<?php

require 'vendor/autoload.php';
$app_boot = require_once 'bootstrap/app.php';

use App\Models\Application;
use App\Services\Deployment\SSHService;

$app = Application::where('name', 'vpsly-test-app')->first();
$ssh = app(SSHService::class);
$ssh->connect($app->server);

// Vide les logs, puis attend qu'un accès soit fait
echo "=== Checking laravel.log NOW ===\n";
$log = $ssh->exec("docker exec vpsly-test-app cat storage/logs/laravel.log 2>&1");
echo (empty(trim($log)) ? "(Log vide — bonne nouvelle, 0 erreur)" : substr($log, 0, 2000)) . "\n";
