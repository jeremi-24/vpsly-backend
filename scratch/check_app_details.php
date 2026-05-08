<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Application;

$app3 = Application::find(3);
if ($app3) {
    echo "Application ID 3 Details:\n";
    print_r($app3->toArray());
} else {
    echo "Application ID 3 not found.\n";
}
