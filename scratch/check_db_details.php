<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\StandaloneDatabase;

echo "Databases Details:\n";
foreach (StandaloneDatabase::all() as $db) {
    echo "- ID: {$db->id}, Name: {$db->name}, Status: {$db->status}, Type: {$db->type}, Adminer URL: {$db->adminer_url}\n";
}
