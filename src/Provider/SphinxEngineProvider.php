<?php

namespace Sphinx\Provider;

use Illuminate\Support\ServiceProvider;
use Sphinx\Engine\SphinxEngine;
use Laravel\Scout\EngineManager;

class SphinxEngineProvider extends ServiceProvider
{
    
    public function boot()
    {
        resolve(EngineManager::class)->extend('sphinx', function ($app) {
            return new SphinxEngine(config('scout.sphinx'));
        });
    }
    
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        $this->app->singleton(EngineManager::class, function ($app) {
            return new EngineManager($app);
        });
    }
}
