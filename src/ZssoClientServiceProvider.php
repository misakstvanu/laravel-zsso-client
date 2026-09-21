<?php

namespace Misakstvanu\ZssoClient;

use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Laravel\Socialite\Contracts\Factory as SocialiteFactory;
use Misakstvanu\ZssoClient\Console\DoctorCommand;
use Misakstvanu\ZssoClient\Events\WebhookReceived;
use Misakstvanu\ZssoClient\Http\Middleware\EnsureSilentLoginAttempted;
use Misakstvanu\ZssoClient\Http\Middleware\VerifyIntegrationToken;
use Misakstvanu\ZssoClient\Socialite\ZssoProvider;
use Misakstvanu\ZssoClient\Webhooks\WebhookRegistry;

class ZssoClientServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/zsso.php', 'zsso');

        $this->app->singleton(Discovery::class);
        $this->app->singleton(JsonWebKeySet::class);
        $this->app->singleton(ClientCredentials::class);
        $this->app->singleton(AppDirectory::class);
        $this->app->singleton(WebhookRegistry::class);
        $this->app->singleton(Zsso::class);
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/zsso.php' => config_path('zsso.php'),
        ], 'zsso-config');

        $this->publishesMigrations([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'zsso-migrations');

        $this->registerRoutes();
        $this->registerMiddleware();
        $this->registerSocialiteDriver();
        $this->registerWebhookHandlers();

        if ($this->app->runningInConsole()) {
            $this->commands([
                DoctorCommand::class,
            ]);
        }
    }

    /**
     * The package's own login routes, always in the `web` middleware group:
     * they need the session for the PKCE verifier, the OAuth state and the
     * intended URL.
     */
    protected function registerRoutes(): void
    {
        Route::middleware('web')->group(__DIR__.'/../routes/zsso.php');

        if (filled(config('zsso.webhook_path'))) {
            Route::middleware('zsso.integration')->group(__DIR__.'/../routes/webhooks.php');
        }
    }

    /**
     * The middleware an app puts on its own routes: `zsso.silent` on the
     * pages a visitor may land on directly, not on the whole app, and
     * `zsso.integration` on the app's `/api/integration/v1/*` routes.
     */
    protected function registerMiddleware(): void
    {
        $router = $this->app->make(Router::class);

        $router->aliasMiddleware('zsso.silent', EnsureSilentLoginAttempted::class);
        $router->aliasMiddleware('zsso.integration', VerifyIntegrationToken::class);
    }

    /**
     * The handlers an app registered with `Zsso::onWebhook()` run from the
     * one `WebhookReceived` listener the package owns, so an app that listens
     * for the event itself and one that registers a typed handler see the
     * same envelope.
     */
    protected function registerWebhookHandlers(): void
    {
        $app = $this->app;

        Event::listen(WebhookReceived::class, function (WebhookReceived $event) use ($app): void {
            $app->make(WebhookRegistry::class)->dispatch($event->envelope);
        });
    }

    /**
     * `Socialite::driver('zsso')`, configured from `config/zsso.php` instead
     * of `config/services.php` so an app configures the client in one place.
     *
     * `Manager::extend()` rebinds the creator to the manager, so the closure
     * must not use `$this`.
     */
    protected function registerSocialiteDriver(): void
    {
        $this->callAfterResolving(SocialiteFactory::class, function ($socialite) {
            $socialite->extend('zsso', function ($app) {
                return (new ZssoProvider(
                    $app->make('request'),
                    (string) config('zsso.client_id'),
                    (string) config('zsso.client_secret'),
                    $app->make('url')->to((string) config('zsso.redirect_path')),
                    discovery: $app->make(Discovery::class),
                ))->setScopes(ZssoProvider::parseScopes(config('zsso.scopes')));
            });
        });
    }
}
