<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\StandaloneDatabase;
use App\Models\Server;

echo "--- ALL DATABASES ---\n";
$dbs = StandaloneDatabase::all();
foreach ($dbs as $db) {
    echo "ID: {$db->id}\n";
    echo "  Name: {$db->name}\n";
    echo "  Type: {$db->type}\n";
    echo "  DB Name: {$db->db_name}\n";
    echo "  User: {$db->db_user}\n";
    echo "  Server ID: {$db->server_id}\n";
    echo "  App ID: {$db->application_id}\n";
    echo "  Adminer UUID: " . ($db->adminer_container_uuid ?? 'null') . "\n";
    echo "  Status: " . ($db->status ?? 'null') . "\n";
    echo "---\n";
}

echo "\n--- SERVER INFO ---\n";
$server = Server::find(1);
if ($server) {
    echo "Server ID: {$server->id}\n";
    echo "Infrastructure: {$server->infrastructure_type}\n";
    echo "IP: {$server->ip}\n";
}
