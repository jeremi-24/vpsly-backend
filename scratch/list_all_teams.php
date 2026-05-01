<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$teams = DB::table('teams')->get();
echo "All Teams:\n";
foreach ($teams as $t) {
    echo "- [{$t->id}] {$t->name} (Owner: {$t->owner_id})\n";
    $members = DB::table('team_user')->where('team_id', $t->id)->pluck('user_id')->toArray();
    echo "  Members: " . implode(', ', $members) . "\n";
}
