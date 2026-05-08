<?php

require 'vendor/autoload.php';
$app_boot = require_once 'bootstrap/app.php';

use App\Models\StandaloneDatabase;

$db = StandaloneDatabase::latest()->first();

if ($db) {
    echo "Database Name: " . $db->db_name . "\n";
    echo "Username: " . $db->db_user . "\n";
    echo "Password: " . $db->db_password . "\n";
    echo "Host: " . $db->uuid . " (via internal network) or " . ($db->server->ip ?? 'N/A') . " (external)\n";
    echo "Port: 3306\n";
} else {
    echo "No database found.\n";
}
