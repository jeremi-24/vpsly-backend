<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

$user = \App\Models\User::where('email', 'jeremiekoue8@gmail.com')->first();
if ($user) {
    $team = $user->currentTeam;
    if ($team) {
        echo "User ID: " . $user->id . "\n";
        echo "Team Name: " . $team->name . "\n";
        echo "Team Owner ID: " . $team->owner_id . "\n";
        echo "Match: " . ($user->id == $team->owner_id ? "OUI" : "NON") . "\n";
        
        // Vérifions aussi si jeremie est dans la table team_user
        $isMember = $team->members()->where('user_id', $user->id)->first();
        echo "Membre de l'équipe (pivot): " . ($isMember ? "OUI (Role: " . $isMember->pivot->role . ")" : "NON") . "\n";
    } else {
        echo "Aucune équipe courante\n";
    }
} else {
    echo "Utilisateur non trouvé\n";
}
