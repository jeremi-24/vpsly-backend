<?php

require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';

use App\Models\Application;
use App\Services\Deployment\SSHService;

$application = Application::where('name', 'vpsly-test-app')->first();
$ssh = app(SSHService::class);
$ssh->connect($application->server);

echo "Testing health check internally...\n";
try {
    $output = $ssh->exec("docker exec vpsly-test-app curl -s -o /dev/null -w '%{http_code}' http://localhost:80/health");
    echo "HTTP Status for /health: " . $output . "\n";
} catch (\Exception $e) {
    echo "Health check failed: " . $e->getMessage() . "\n";
}

try {
    $output = $ssh->exec("docker exec vpsly-test-app curl -s -o /dev/null -w '%{http_code}' http://localhost:80/");
    echo "HTTP Status for /: " . $output . "\n";
} catch (\Exception $e) {
    echo "Root check failed: " . $e->getMessage() . "\n";
}
