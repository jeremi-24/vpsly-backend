<?php

require 'vendor/autoload.php';
$app_boot = require_once 'bootstrap/app.php';

use App\Models\Application;

$app = Application::where('name', 'vpsly-test-app')->first();

if ($app) {
    echo "Path: " . ($app->healthcheck_path ?? 'NULL') . "\n";
    echo "Codes: " . ($app->healthcheck_status_codes ?? 'NULL') . "\n";
} else {
    echo "Not found.\n";
}
