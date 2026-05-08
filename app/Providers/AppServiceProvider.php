<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

use Illuminate\Support\Facades\Schema;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(\App\Services\Deployment\SSHService::class);
    }


    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if ($this->app->environment('production') || $this->app->environment('sandbox')) {
            \Illuminate\Support\Facades\URL::forceScheme('https');
        }
        Schema::defaultStringLength(191);

        // Global Log Redaction
        \Illuminate\Support\Facades\Log::listen(function ($event) {
            $message = $event->message;
            if (!is_string($message)) return;

            $secrets = [
                'DB_PASSWORD', 'REDIS_PASSWORD', 'GITHUB_TOKEN', 'SSH_KEY',
                'MYSQL_ROOT_PASSWORD', 'POSTGRES_PASSWORD', 'PASSWORD'
            ];

            foreach ($secrets as $secret) {
                // Mask Assignment: KEY=VALUE or KEY="VALUE"
                $message = preg_replace('/(' . $secret . '=["\']?)([^"\']\S+)(["\']?)/i', '$1[REDACTED]$3', $message);
                // Mask Flag: -p"PASSWORD"
                $message = preg_replace('/(-p["\']?)([^"\']\S+)(["\']?)/i', '$1[REDACTED]$3', $message);
            }

            $event->message = $message;
        });
    }
}
