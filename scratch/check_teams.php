<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\User;
use App\Models\Team;

$emails = ['jeremiekoue8@gmail.com', 'jeremiekoue859@gmail.com'];

foreach ($emails as $email) {
    $user = User::where('email', $email)->first();

    if (!$user) {
        echo "User not found: $email\n";
        continue;
    }

    echo "User: {$user->name} (ID: {$user->id}) - {$user->email}\n";
    echo "Current Team ID: " . ($user->current_team_id ?? 'NONE') . "\n";
    echo "Teams attached:\n";
    foreach ($user->teams as $team) {
        echo "- [{$team->id}] {$team->name} (Role: {$team->pivot->role})\n";
    }
    echo "-------------------\n";
}

echo "\nServers in DB:\n";
$servers = DB::table('servers')->get();
foreach ($servers as $s) {
    echo "- [{$s->id}] {$s->name} | Team ID: " . ($s->team_id ?? 'NULL') . " | User ID: {$s->user_id}\n";
}

echo "\nDatabases in DB:\n";
$dbs = DB::table('standalone_databases')->get();
foreach ($dbs as $db) {
    echo "- [{$db->id}] {$db->name} | Team ID: " . ($db->team_id ?? 'NULL') . "\n";
}
