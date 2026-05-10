<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Server;
use App\Models\Application;
use App\Services\Deployment\SSHService;

try {
    $ssh = app(SSHService::class);
    $server = Server::find(1);
    $ssh->connect($server);

    echo "--- NGINX ACTIVE CONFIG ---\n";
    echo $ssh->exec("cat /etc/nginx/sites-enabled/test_vpsly_tech.vpsly.conf 2>&1");

    echo "\n--- CERTBOT FILES ---\n";
    echo $ssh->exec("sudo ls -R /etc/letsencrypt/live/test.vpsly.tech 2>&1 || echo 'No certificates found'");

    echo "\n--- DB STATE CHECK (FORCED RE-FETCH) ---\n";
    $app = Application::find(7);
    echo "App 7 - nginx_configured: " . ($app->nginx_configured ? 'TRUE' : 'FALSE') . "\n";
    echo "App 7 - deploy_script length: " . strlen($app->deploy_script) . "\n";

} catch (\Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
