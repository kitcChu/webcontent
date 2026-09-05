<?php

namespace Kit\WebContent;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;
use Kit\WebContent\Console\Commands\WebContentResearchCommand;

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

        // Migrations (web_contents + agent proposals)
        $this->publishesMigrations([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'webcontent-migrations');

        // Blade views (sitemap, proposal review/decision pages, email)
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'webcontent');

        // Package routes
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');

        if ($this->app->runningInConsole()) {
            $this->commands([WebContentResearchCommand::class]);

            $this->publishes([
                __DIR__.'/../resources/js/Pages/CMS' => resource_path('js/Pages/CMS'),
                __DIR__.'/../resources/js/Layouts'   => resource_path('js/Layouts'),
            ], 'webcontent-vue');
        }

        $this->registerSchedule();
    }

    /**
     * Registers the scheduled agent run when
     * webcontent.agent.schedule_enabled is true (cron configurable).
     * Requires the host to run `php artisan schedule:work` or a cron entry
     * for schedule:run — the standard Laravel scheduler.
     */
    protected function registerSchedule(): void
    {
        $this->app->booted(function ($app) {
            if (!config('webcontent.agent.schedule_enabled')) {
                return;
            }

            $app->make(Schedule::class)
                ->command(WebContentResearchCommand::class)
                ->cron((string) config('webcontent.agent.cron', '0 6 * * *'))
                ->withoutOverlapping()
                ->onOneServer();
        });
    }
}
