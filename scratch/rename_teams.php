<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Team;
use App\Models\User;

$teams = Team::all();
foreach ($teams as $team) {
    $owner = User::find($team->owner_id);
    if ($owner && (str_contains($team->name, 'Personal Space') || $team->name === 'Mon Espace')) {
        $oldName = $team->name;
        $team->name = "Equipe de " . $owner->name;
        $team->save();
        echo "Renamed '{$oldName}' to '{$team->name}'\n";
    }
}
