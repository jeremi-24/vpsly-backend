<?php

require 'vendor/autoload.php';
$app_boot = require_once 'bootstrap/app.php';

use App\Models\Server;
use App\Services\Deployment\SSHService;

$server = Server::first();
$ssh = app(SSHService::class);
$ssh->connect($server);

echo "--- Adminer Labels ---\n";
echo $ssh->exec("docker inspect adminer-9bf72b31-b8c6-41f5-84fe-bfea48ac8477 --format '{{json .Config.Labels}}'") . "\n";
