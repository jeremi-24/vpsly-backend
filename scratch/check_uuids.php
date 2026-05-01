<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\Schema;

$tables = ['servers', 'applications', 'standalone_databases'];
foreach ($tables as $t) {
    echo "Table: {$t} | UUID: " . (Schema::hasColumn($t, 'uuid') ? 'OK' : 'MISSING') . "\n";
}
