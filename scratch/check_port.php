<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';

$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$application = \App\Models\Application::where('name', 'portfolio1')->first();
if ($application) {
    $ssh = app(\App\Services\Deployment\SSHService::class);
    $ssh->connect($application->server);
    $output = $ssh->exec('ss -tulpn | grep :3000');
    echo "Port 3000 check:\n" . $output . "\n";
} else {
    echo "App portfolio1 not found.\n";
}
