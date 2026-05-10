<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Server;
use App\Services\Deployment\SSHService;

$ssh = app(SSHService::class);
$server = Server::find(1);
$ssh->connect($server);

echo "--- RUNNING CERTBOT MANUALLY ---\n";
$cmd = "sudo certbot --nginx -d test.vpsly.tech --non-interactive --agree-tos -m hello@vpsly.tech --debug";
echo $ssh->exec($cmd);
