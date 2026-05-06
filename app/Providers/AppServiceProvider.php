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
    }
}
