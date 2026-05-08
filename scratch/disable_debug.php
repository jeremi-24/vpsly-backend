<?php

require 'vendor/autoload.php';
$app_boot = require_once 'bootstrap/app.php';

use App\Models\Application;
use App\Services\Deployment\SSHService;

$app = Application::where('name', 'vpsly-test-app')->first();
$ssh = app(SSHService::class);
$ssh->connect($app->server);

echo "--- Disabling APP_DEBUG (was enabled for debug) --- \n";
$ssh->exec("docker exec vpsly-test-app sed -i 's/APP_DEBUG=true/APP_DEBUG=false/g' .env");
$ssh->exec("docker exec vpsly-test-app php artisan config:clear");
echo "Done.\n";
