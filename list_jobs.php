<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

$jobs = \Illuminate\Support\Facades\DB::table('jobs')->get();
foreach ($jobs as $j) {
    $payload = json_decode($j->payload);
    echo "Job: " . ($payload->displayName ?? 'Unknown') . " | Queue: " . $j->queue . " | Reserved: " . ($j->reserved_at ? 'YES' : 'NO') . "\n";
}
