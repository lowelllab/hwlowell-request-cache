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
        $this->mergeConfigFrom(__DIR__ . '/../config/request_cache.php', 'request_cache');

        //不给构造函数传配置：容器单例应当跟随全局配置，
        //这样运行时调用 CacheConfig::setXxx() 仍能影响它
        $this->app->singleton('request-cache', function ($app) {
            return new RequestCache();
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
            __DIR__ . '/../config/request_cache.php' => config_path('request_cache.php'),
        ], 'config');
    }

}