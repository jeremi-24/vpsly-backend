<?php

require 'vendor/autoload.php';
$app_boot = require_once 'bootstrap/app.php';

use App\Models\Application;
use App\Services\Deployment\SSHService;

$app = Application::where('name', 'vpsly-test-app')->first();
$ssh = app(SSHService::class);
$ssh->connect($app->server);

echo "=== Clearing view cache ===\n";
echo $ssh->exec("docker exec vpsly-test-app php artisan view:clear 2>&1") . "\n";

echo "=== Optimizing ===\n";
echo $ssh->exec("docker exec vpsly-test-app php artisan optimize 2>&1") . "\n";

echo "=== Verifying views NOT cached anymore ===\n";
echo $ssh->exec("docker exec vpsly-test-app php artisan about 2>&1 | grep -E 'Views|Config|Routes'") . "\n";
