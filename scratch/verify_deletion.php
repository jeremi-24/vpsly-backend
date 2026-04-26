<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Server;
use App\Services\Deployment\SSHService;

$server = Server::first();
$ssh = app(SSHService::class);

$slug = $argv[1] ?? 'portfolio1';

try {
    $ssh->connect($server);
    $path = "/var/www/vpsly/apps/$slug";
    
    echo "--- Verification pour $slug ---\n";
    
    // Check Folder
    $exists = trim($ssh->exec("[ -d $path ] && echo 'EXIST' || echo 'GONE'"));
    echo "Dossier ($path): $exists\n";
    
    // Check Containers
    $containers = trim($ssh->exec("docker ps -a --filter name=$slug --format '{{.Names}}'"));
    echo "Containers:\n" . ($containers ?: "Aucun container trouvé") . "\n";
    
    // Check Networks
    $networks = trim($ssh->exec("docker network ls --filter name=$slug --format '{{.Name}}'"));
    echo "Networks:\n" . ($networks ?: "Aucun réseau trouvé") . "\n";

} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
