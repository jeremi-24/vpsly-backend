<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

$user = \App\Models\User::where('email', 'jeremiekoue8@gmail.com')->first();
if ($user) {
    echo "Current Team ID: " . $user->current_team_id . "\n";
    foreach ($user->teams as $t) {
        echo "Team ID: " . $t->id . " | Name: " . $t->name . " | Owner: " . $t->owner_id . " | Your Role: " . $t->pivot->role . "\n";
    }
}
