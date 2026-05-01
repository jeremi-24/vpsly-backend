<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$teamId = 3;
$newOwnerId = 1;
$oldOwnerId = 2;

DB::transaction(function () use ($teamId, $newOwnerId, $oldOwnerId) {
    // 1. Update team owner
    DB::table('teams')->where('id', $teamId)->update(['owner_id' => $newOwnerId]);

    // 2. Remove old owner from pivot
    DB::table('team_user')->where('team_id', $teamId)->where('user_id', $oldOwnerId)->delete();

    // 3. Add new owner to pivot
    DB::table('team_user')->insert([
        'team_id' => $teamId,
        'user_id' => $newOwnerId,
        'role' => 'owner',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
});

echo "Transfer completed: Team {$teamId} is now owned by User {$newOwnerId}.\n";
