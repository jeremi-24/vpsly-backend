<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Application;
use App\Models\StandaloneDatabase;

echo "Applications:\n";
foreach (Application::all() as $app) {
    echo "- ID: {$app->id}, Name: {$app->name}, Status: {$app->status}, FQDN: {$app->fqdn}\n";
}

echo "\nDatabases:\n";
foreach (StandaloneDatabase::all() as $db) {
    echo "- ID: {$db->id}, Name: {$db->name}, Status: {$db->status}, Type: {$db->type}\n";
}
