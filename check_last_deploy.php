<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\Deployment;

$deploy = Deployment::orderBy('id', 'desc')->first();
if ($deploy) {
    echo "Dernier déploiement :\n";
    echo "ID: " . $deploy->id . "\n";
    echo "Status: " . $deploy->status . "\n";
    echo "Application ID: " . $deploy->application_id . "\n";
    echo "Créé le: " . $deploy->created_at . "\n";
} else {
    echo "Aucun déploiement trouvé.\n";
}
