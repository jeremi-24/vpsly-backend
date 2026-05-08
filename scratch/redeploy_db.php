<?php

require 'vendor/autoload.php';
$app_boot = require_once 'bootstrap/app.php';

use App\Models\StandaloneDatabase;
use App\Services\Deployment\DatabaseProvisioner;

$db = StandaloneDatabase::where('db_name', 'test')->first(); // C'est celle de l'utilisateur
$provisioner = app(DatabaseProvisioner::class);

echo "Redeploying database: " . $db->db_name . "\n";
$provisioner->provision($db);
echo "Done.\n";
