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
    $path = "/var/www/vpsly/apps/vpsly-test-app";
    $exists = trim($ssh->exec("[ -d $path ] && echo 'EXIST' || echo 'GONE'"));
    
    echo "Folder Status: $exists\n";
    
    if ($exists === 'EXIST') {
        echo "Cleaning up manually...\n";
        $ssh->exec("cd $path && docker compose down -v || true");
        $ssh->exec("rm -rf $path");
        echo "Cleanup DONE.\n";
    } else {
        echo "Nothing to cleanup.\n";
    }
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
