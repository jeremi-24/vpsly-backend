<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';

$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$application = \App\Models\Application::where('name', 'vpsly-test-app')->first();

if ($application) {
    $application->update([
        'healthcheck_path' => '/health',
        'healthcheck_status_codes' => '200,301,302',
        'ignore_healthcheck_warnings' => true
    ]);
    echo "Application updated successfully.\n";
} else {
    echo "Application not found.\n";
}
