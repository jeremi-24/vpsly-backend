<?php

use App\Models\Server;
use App\Services\Deployment\SSHService;

$server = Server::where('ip', '62.238.12.74')->first();
$ssh = app(SSHService::class);
$ssh->connect($server);

echo "--- MEMORY ---\n";
echo $ssh->exec('free -h');
echo "\n--- DISK ---\n";
echo $ssh->exec('df -h /');
echo "\n--- DOCKER INFO ---\n";
echo $ssh->exec('docker info | grep "Storage Driver"');
echo "\n--- NIXPACKS VERSION ---\n";
echo $ssh->exec('nixpacks --version');
