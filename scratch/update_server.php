<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();
$s = App\Models\Server::find(1);
if($s) {
    $s->infrastructure_type = 'legacy';
    $s->save();
    echo "Server updated to legacy\n";
} else {
    echo "Server not found\n";
}
