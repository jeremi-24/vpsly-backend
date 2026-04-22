<?php

require __DIR__ . '/../vendor/autoload.php';

$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Server;
use phpseclib3\Crypt\PublicKeyLoader;

$server = Server::find(1);
if (!$server) {
    echo "Server 1 not found\n";
    exit(1);
}

try {
    $key = PublicKeyLoader::load($server->ssh_private_key);
    $sshService = app(App\Services\Deployment\SSHService::class);
    $sshService->connect($server);
    
    $path = "/var/www/vpsly/apps/spotup2";
    $gitDir = rtrim($path, '/') . '/.git';
    $cmd = "[ -d \"{$gitDir}\" ] && echo \"yes\" || echo \"no\"";
    
    echo "Executing: $cmd\n";
    $result = $sshService->exec($cmd);
    echo "Result: [" . trim($result) . "]\n";
    
} catch (\Exception $e) {
    echo "Exception: " . $e->getMessage() . "\n";
}

