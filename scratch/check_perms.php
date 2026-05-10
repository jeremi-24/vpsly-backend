<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Server;
use App\Services\Deployment\SSHService;

try {
    $ssh = app(SSHService::class);
    $server = Server::find(1);
    $ssh->connect($server);

    echo "--- PERMISSIONS CHECK ---\n";
    echo "ls -ld /var/www: " . $ssh->exec("ls -ld /var/www") . "\n";
    echo "ls -ld /var/www/vpsly-test-app: " . $ssh->exec("ls -ld /var/www/vpsly-test-app") . "\n";
    echo "ls -ld /var/www/vpsly-test-app/public: " . $ssh->exec("ls -ld /var/www/vpsly-test-app/public") . "\n";
    echo "ls -l /var/www/vpsly-test-app/public/index.php: " . $ssh->exec("ls -l /var/www/vpsly-test-app/public/index.php") . "\n";

    echo "\n--- PHP-FPM SOCKET CHECK ---\n";
    echo $ssh->exec("ls -l /var/run/php/php8.4-fpm.sock || echo 'Socket NOT found'");

} catch (\Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
