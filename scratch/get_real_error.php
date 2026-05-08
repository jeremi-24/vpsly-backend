<?php

require 'vendor/autoload.php';
$app_boot = require_once 'bootstrap/app.php';

use App\Models\Application;
use App\Services\Deployment\SSHService;

$app = Application::where('name', 'vpsly-test-app')->first();
$ssh = app(SSHService::class);
$ssh->connect($app->server);

echo "=== artisan about (check config) ===\n";
echo $ssh->exec("docker exec vpsly-test-app php artisan about 2>&1 | head -40") . "\n";

echo "=== artisan config:show database.default ===\n";
echo $ssh->exec("docker exec vpsly-test-app php artisan config:show database.default 2>&1") . "\n";

echo "=== Test DB connection ===\n";
echo $ssh->exec("docker exec vpsly-test-app php artisan migrate:status 2>&1 | head -20") . "\n";
