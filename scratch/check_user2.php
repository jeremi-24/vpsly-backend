<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$user2 = DB::table('users')->where('id', 2)->first();
if (!$user2) {
    echo "User 2 not found!\n";
    exit;
}

echo "User 2: {$user2->email}\n";
echo "Current Team ID: " . ($user2->current_team_id ?? 'NULL') . "\n";

$memberships = DB::table('team_user')->where('user_id', 2)->get();
echo "Memberships:\n";
foreach ($memberships as $m) {
    echo "- Team ID: {$m->team_id} | Role: {$m->role}\n";
}

echo "\nServers for Team " . ($user2->current_team_id ?: 'NULL') . ":\n";
$servers = DB::table('servers')->where('team_id', $user2->current_team_id)->get();
foreach ($servers as $s) {
    echo "- [{$s->id}] {$s->name}\n";
}

echo "\nApplications for Team " . ($user2->current_team_id ?: 'NULL') . ":\n";
$apps = DB::table('applications')->where('team_id', $user2->current_team_id)->get();
foreach ($apps as $a) {
    echo "- [{$a->id}] {$a->name}\n";
}
