<?php

require 'vendor/autoload.php';
$app_boot = require_once 'bootstrap/app.php';

use App\Models\Application;
use App\Models\Deployment;
use App\Jobs\DeployApplicationJob;
use App\Enums\DeploymentStatus;

$app = Application::where('name', 'vpsly-test-app')->first();

$deployment = Deployment::create([
    'application_id' => $app->id,
    'status'         => DeploymentStatus::PENDING->value,
    'started_at'     => now(),
    'commit_sha'     => '5beeae9',
    'commit_message' => 'fix: add missing Livewire layout components/layouts/app.blade.php',
]);

$app->update(['is_deploying' => true]);

dispatch(new DeployApplicationJob($deployment->id));
echo "Job dispatched — déploiement #{$deployment->id}\n";
