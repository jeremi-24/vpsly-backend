<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Server;
use App\Services\Deployment\SSHService;

$ssh = app(SSHService::class);
$server = Server::find(1);
$ssh->connect($server);

$domain = "test.vpsly.tech";
echo "Server IP from DB: " . $server->ip . "\n";
echo "Resolving $domain on server via PHP: ";
echo $ssh->exec("php -r \"echo gethostbyname('$domain');\"") . "\n";

echo "Resolving $domain on server via host command: ";
echo $ssh->exec("host $domain") . "\n";

echo "Checking if nginx is actually listening on 443: ";
echo $ssh->exec("netstat -tuln | grep :443 || echo 'Not listening'") . "\n";
