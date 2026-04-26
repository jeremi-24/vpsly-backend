<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Server;
use App\Services\Deployment\SSHService;

$server = Server::first();
$ssh = app(SSHService::class);

$slug = $argv[1] ?? 'portfolio1';

try {
    $ssh->connect($server);
    echo "Forcing removal of $slug...\n";
    echo $ssh->exec("docker rm -f $slug || echo 'Already gone'") . "\n";
    echo $ssh->exec("rm -rf /var/www/vpsly/apps/$slug") . "\n";
    echo "Cleanup DONE.\n";
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
