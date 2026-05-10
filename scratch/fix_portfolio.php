<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';

$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$application = \App\Models\Application::where('name', 'portfolio1')->first();
if ($application) {
    $application->update([
        'deployment_mode' => 'legacy_new',
        'domain' => 'api.vpsly.tech',
        'nginx_configured' => false
    ]);
    echo "App portfolio1 updated successfully.\n";
} else {
    echo "App portfolio1 not found.\n";
}
