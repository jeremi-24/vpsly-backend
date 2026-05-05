<?php

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';

$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Team;
use App\Models\User;

$teamId = 3; // The "jeremie" team
$team = Team::find($teamId);

if ($team) {
    echo "Deleting team: {$team->name} (ID: {$team->id})...\n";
    
    // Switch owner back to team 1 if they are on team 3
    $owner = User::find($team->owner_id);
    if ($owner && $owner->current_team_id == $teamId) {
        $owner->update(['current_team_id' => 1]);
        echo "Switched owner {$owner->name} back to team 1.\n";
    }

    $team->delete();
    echo "Team deleted successfully.\n";
} else {
    echo "Team not found.\n";
}
