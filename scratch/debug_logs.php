<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Deployment;

$d = Deployment::where('application_id', 7)->latest()->first();
if ($d) {
    echo "Deployment ID: " . $d->id . " Status: " . $d->status . "\n";
    foreach($d->logs as $log) {
        echo "[" . $log['timestamp'] . "] " . $log['type'] . ": " . $log['message'] . "\n";
    }
} else {
    echo "No deployment found for App ID 7\n";
}
