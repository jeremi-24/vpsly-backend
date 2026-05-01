<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Server;
use App\Services\Deployment\SSHService;

$server = Server::where('ip', '62.238.12.74')->first();
if (!$server) {
    echo "Server not found\n";
    exit(1);
}

$ssh = app(SSHService::class);
try {
    $ssh->connect($server);
    echo "--- DOCKER PS ---\n";
    echo $ssh->exec('docker ps -a --format "table {{.Names}}\t{{.Status}}\t{{.Image}}"');
    echo "--- CONTENU docker-compose.yml ---\n";
    echo $ssh->exec("cat /var/www/vpsly/apps/stock/docker-compose.yml");
    echo "\n--- CONTENU .env ---\n";
    echo $ssh->exec("cat /var/www/vpsly/apps/stock/.env");
    echo "\n--- TRAEFIK CONFIG (inspect) ---\n";
    echo $ssh->exec("docker inspect traefik --format '{{json .Args}}'");
    $ssh->disconnect();
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
