<?php

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Server;
use App\Services\Deployment\SSHService;

$server = Server::where('ip', '62.238.12.74')->first();
$ssh = app(SSHService::class);

try {
    echo "--- Connexion au serveur {$server->ip} ---\n";
    $ssh->connect($server);
    
    echo "--- Test de Nixpacks Build ---\n";
    // On tente juste de construire l'image 'vpsly-test-image'
    $command = "cd /var/www/vpsly/apps/vpsly-test-app && nixpacks build . --name vpsly-test-image --inline-cache";
    echo "Commande : $command\n";
    $ssh->stream($command, function($line) {
        echo "LOG: $line\n";
    });
    
    $ssh->disconnect();
} catch (\Exception $e) {
    echo "Erreur : " . $e->getMessage() . "\n";
}
