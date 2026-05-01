<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\User;
use App\Models\Server;
use Illuminate\Support\Facades\Auth;

$user = User::find(2);
Auth::login($user);

echo "User: " . Auth::user()->email . "\n";
echo "Active Team ID: " . Auth::user()->current_team_id . "\n";

echo "Visible Servers count: " . Server::count() . "\n";
foreach (Server::all() as $s) {
    echo "- [{$s->id}] {$s->name} (Team ID: {$s->team_id})\n";
}

// Test find
$serverId = 1;
$server = Server::find($serverId);
if ($server) {
    echo "Server {$serverId} FOUND\n";
} else {
    echo "Server {$serverId} NOT FOUND (404 simulation)\n";
}
