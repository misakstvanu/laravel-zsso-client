<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Routing\Router;
use Misakstvanu\ZssoClient\Console\DoctorCommand;
use Misakstvanu\ZssoClient\Http\Middleware\EnsureSilentLoginAttempted;
use Misakstvanu\ZssoClient\Http\Middleware\VerifyIntegrationToken;
use Misakstvanu\ZssoClient\ZssoClientServiceProvider;

it('boots and merges the package config', function () {
    expect(app()->getProviders(ZssoClientServiceProvider::class))->not->toBeEmpty()
        ->and(config('zsso.scopes'))->toBe('profile email')
        ->and(config('zsso.redirect_path'))->toBe('/auth/zsso/callback')
        ->and(config('zsso.post_login_path'))->toBe('/')
        ->and(config('zsso.post_logout_path'))->toBe('/login')
        ->and(config('zsso.login_path'))->toBe('/login')
        ->and(config('zsso.silent_login'))->toBeTrue()
        ->and(config('zsso.apps'))->toBe([])
        ->and(config('zsso.webhook_path'))->toBe('/api/integration/v1/webhooks')
        ->and(config('zsso.cache_ttl'))->toBe(3600)
        ->and(array_keys(config('zsso')))->toBe([
            'server_url', 'client_id', 'client_secret', 'app_slug', 'scopes',
            'redirect_path', 'post_login_path', 'post_logout_path', 'login_path',
            'silent_login', 'apps', 'webhook_path', 'cache_ttl',
        ]);
});

it('publishes the config and the migrations', function () {
    $config = ZssoClientServiceProvider::pathsToPublish(ZssoClientServiceProvider::class, 'zsso-config');
    $migrations = ZssoClientServiceProvider::pathsToPublish(ZssoClientServiceProvider::class, 'zsso-migrations');

    // Keys are absolute paths, so `toHaveKey` (dot notation) cannot be used.
    expect($config)->toHaveCount(1)
        ->and(realpath(array_key_first($config)))->toBe(realpath(__DIR__.'/../config/zsso.php'))
        ->and(reset($config))->toEndWith('config/zsso.php')
        ->and($migrations)->toHaveCount(1)
        ->and(realpath(array_key_first($migrations)))->toBe(realpath(__DIR__.'/../database/migrations'))
        ->and(reset($migrations))->toEndWith('migrations');
});

it('registers the zsso commands', function () {
    expect(array_keys(app(Kernel::class)->all()))->toContain('zsso:doctor')
        ->and(app(Kernel::class)->all()['zsso:doctor'])->toBeInstanceOf(DoctorCommand::class);
});

it('aliases the zsso middleware', function () {
    $middleware = app(Router::class)->getMiddleware();

    // Keys carry dots, so `toHaveKey` (dot notation) cannot be used.
    expect(array_keys($middleware))->toContain('zsso.silent', 'zsso.integration')
        ->and($middleware['zsso.silent'])->toBe(EnsureSilentLoginAttempted::class)
        ->and($middleware['zsso.integration'])->toBe(VerifyIntegrationToken::class);
});
