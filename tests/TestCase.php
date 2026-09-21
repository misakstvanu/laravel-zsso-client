<?php

namespace Misakstvanu\ZssoClient\Tests;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\SocialiteServiceProvider;
use Misakstvanu\ZssoClient\Tests\Fixtures\User;
use Misakstvanu\ZssoClient\ZssoClientServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

use function Orchestra\Testbench\default_migration_path;

abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    /**
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [SocialiteServiceProvider::class, ZssoClientServiceProvider::class];
    }

    /**
     * Laravel's own tables (the fixture app's `users`) plus the package's
     * `zsso_tokens`, which an app gets by publishing the migrations.
     *
     * With `RefreshDatabase` these paths are only registered here; the
     * `migrate:fresh` that follows is what runs them, so migrating by hand
     * would be undone.
     */
    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom([
            default_migration_path(),
            __DIR__.'/../database/migrations',
        ]);
    }

    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('auth.providers.users.model', User::class);
    }
}
