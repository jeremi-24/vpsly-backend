<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$latestTeam = DB::table('teams')->orderBy('id', 'desc')->first();
if (!$latestTeam) {
    echo "No teams found.\n";
    exit;
}

echo "Latest Team: {$latestTeam->name} (ID: {$latestTeam->id})\n";
echo "Owner ID: {$latestTeam->owner_id}\n";

$members = DB::table('team_user')->where('team_id', $latestTeam->id)->get();
echo "Members in team_user:\n";
foreach ($members as $m) {
    echo "- User ID: {$m->user_id} | Role: {$m->role}\n";
}
