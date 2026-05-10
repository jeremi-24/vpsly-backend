<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Application;

$app = Application::find(7);
if ($app) {
    $script = "composer install --no-dev --optimize-autoloader --no-interaction\n" .
              "npm install\n" .
              "npm run build\n" .
              "php artisan migrate:fresh --force\n" .
              "php artisan config:cache\n" .
              "php artisan route:cache\n" .
              "php artisan view:cache\n" .
              "touch storage/logs/laravel.log\n" .
              "chmod -R 775 storage bootstrap/cache\n" .
              "sudo chown -R www-data:www-data storage bootstrap/cache";
              
    $app->update([
        'deploy_script' => $script,
        'nginx_configured' => false
    ]);
    
    echo "Application 7 updated successfully: Script fixed and Nginx reset.\n";
} else {
    echo "Application 7 not found.\n";
}
