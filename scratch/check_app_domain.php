<?php

require 'vendor/autoload.php';
$app_boot = require_once 'bootstrap/app.php';

use App\Models\Application;

$app = Application::where('name', 'vpsly-test-app')->first();

if ($app) {
    echo "ID: " . $app->id . "\n";
    echo "Name: " . $app->name . "\n";
    echo "Domain: " . ($app->domain ?: 'NULL') . "\n";
    echo "Server IP: " . ($app->server->ip ?? 'NULL') . "\n";
} else {
    echo "Application not found.\n";
}
