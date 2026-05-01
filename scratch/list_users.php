<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$users = DB::table('users')->get();
echo "Users list:\n";
foreach ($users as $u) {
    echo "- [{$u->id}] {$u->email}\n";
}
