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
    echo "--- Debug portfolio1 ---\n";
    echo "Status: " . $ssh->exec("docker inspect portfolio1 --format '{{.State.Status}}'") . "\n";
    echo "Full Info: " . $ssh->exec("docker ps -a --filter name=portfolio1 --format 'table {{.ID}}\t{{.Names}}\t{{.Status}}'") . "\n";
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
