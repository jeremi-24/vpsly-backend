<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Application;
use App\Models\Deployment;
use App\Jobs\DeployApplicationJob;

$application = Application::find(3);
if (!$application) {
    echo "Application 3 not found.\n";
    exit;
}

$deployment = $application->deployments()->create([
    'status' => 'pending',
    'type' => 'manual',
]);

echo "Created deployment ID: {$deployment->id}\n";
DeployApplicationJob::dispatch($deployment->id);
echo "Job dispatched.\n";
