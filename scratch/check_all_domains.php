<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Application;

foreach (Application::all() as $app) {
    echo "- ID: {$app->id}, Name: {$app->name}, Domain: {$app->domain}\n";
}
