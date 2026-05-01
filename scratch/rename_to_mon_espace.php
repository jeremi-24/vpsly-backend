<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Team;

$teams = Team::where('name', 'LIKE', 'Equipe de %')->get();
foreach ($teams as $team) {
    $oldName = $team->name;
    $team->name = "Mon Espace";
    $team->save();
    echo "Renamed '{$oldName}' to '{$team->name}'\n";
}
