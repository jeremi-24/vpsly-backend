<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Application;
use App\Models\EnvironmentVariable;

$app = Application::find(7);
if (!$app) { die("App 7 not found\n"); }

// 1. Reset nginx flag pour forcer la reconfiguration complète
$app->update(['nginx_configured' => false]);
echo "nginx_configured reset to FALSE\n";

// 2. Vérifier l'APP_KEY actuel
$appKey = $app->environmentVariables()->where('key', 'APP_KEY')->first();
if ($appKey) {
    echo "Current APP_KEY value: '{$appKey->value}' (length: " . strlen($appKey->value) . ")\n";
    // Supprimer pour forcer la régénération
    $appKey->delete();
    echo "APP_KEY deleted — will be auto-generated on next deploy\n";
} else {
    echo "No APP_KEY found in DB — will be auto-generated on next deploy\n";
}

// 3. Vérifier toutes les env vars actuelles
echo "\n--- Current env vars in DB ---\n";
foreach ($app->environmentVariables()->get() as $var) {
    $display = strlen($var->value) > 20 ? substr($var->value, 0, 20) . '...' : $var->value;
    echo "{$var->key} = {$display}\n";
}
