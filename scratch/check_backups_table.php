<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\Schema;

try {
    $columns = Schema::getColumnListing('backups');
    echo "Columns in backups table:\n";
    print_r($columns);
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
