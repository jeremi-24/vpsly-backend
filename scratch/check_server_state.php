<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Server;
use App\Services\Deployment\SSHService;

$server = Server::first();
$ssh = app(SSHService::class);

try {
    $ssh->connect($server);
    echo "Hostname: " . trim($ssh->exec("hostname")) . "\n";
    echo "IP: " . $server->ip . "\n";
    echo "--- Active Containers ---\n";
    echo $ssh->exec("docker ps --format '{{.Names}}'") . "\n";
    
    echo "--- Full ps for portfolio1 ---\n";
    echo $ssh->exec("docker ps -a | grep portfolio1 || echo 'NOT FOUND'") . "\n";
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
