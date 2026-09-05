<?php

namespace Kit\WebContent\Tests;

use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Kit\WebContent\WebContentServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [WebContentServiceProvider::class];
    }

    protected function getEnvironmentSetUp($app): void
    {
        // Pin the test database so a host shell's DB_* env vars can't leak in.
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        // Admin editor: no auth middleware in tests; gate allowed by default.
        $app['config']->set('webcontent.middleware', ['web']);
        $app['config']->set('webcontent.gate', 'manage-web-content');

        // Keep sessions out of the database (no sessions table is migrated).
        $app['config']->set('session.driver', 'array');
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Inertia renders into this stub root view during tests.
        Inertia::setRootView('webcontent-test::app');

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->artisan('migrate')->run();

        Gate::define('manage-web-content', fn ($user = null) => true);
    }
}
