<?php

namespace HwlowellRequestCache;

use Illuminate\Support\ServiceProvider;

class RequestCacheServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     *
     * @return void
     */
    public function register()
    {
        $this->app->singleton('request-cache', function ($app) {
            return new RequestCache(config('request_cache', []));
        });

        $this->app->singleton('cache-monitor', function ($app) {
            return new CacheMonitor();
        });
    }

    /**
     * Bootstrap services.
     *
     * @return void
     */
    public function boot()
    {
        //Publish configuration if needed
        $this->publishes([
            __DIR__ . '/../config/request_cache.php' => config_path('request-cache.php'),
        ], 'config');
    }

    /**
     * Get the services provided by the provider.
     *
     * @return array
     */
    public function provides()
    {
        return [
            'request-cache',
            'cache-monitor',
        ];
    }
}