<?php

namespace Kit\WebContent;

use Illuminate\Support\ServiceProvider;

class WebContentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/webcontent.php', 'webcontent');
    }

    public function boot(): void
    {
        // Config
        $this->publishes([
            __DIR__.'/../config/webcontent.php' => config_path('webcontent.php'),
        ], 'webcontent-config');

        // Migration (single consolidated schema)
        $this->publishesMigrations([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'webcontent-migrations');

        // Blade views (sitemap) under the webcontent:: namespace
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'webcontent');

        // Package routes
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');

        // Publishable Inertia page components + layout (copied into the host
        // app's JS tree so Vite can compile them; see README "Front-end setup")
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../resources/js/Pages/CMS' => resource_path('js/Pages/CMS'),
                __DIR__.'/../resources/js/Layouts'   => resource_path('js/Layouts'),
            ], 'webcontent-vue');
        }
    }
}
