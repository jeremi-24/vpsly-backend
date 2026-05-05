<?php

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';

$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\User;
use App\Models\Team;

$users = User::all();
foreach ($users as $user) {
    $teams = Team::where('owner_id', $user->id)->get();
    if ($teams->count() > 1) {
        echo "User ID: {$user->id}, Name: {$user->name}\n";
        foreach ($teams as $team) {
            echo "  - Team ID: {$team->id}, Name: {$team->name}, Plan: {$team->plan}\n";
        }
    }
}
