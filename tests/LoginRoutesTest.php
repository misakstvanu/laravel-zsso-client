<?php

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Misakstvanu\ZssoClient\Contracts\ProvisionsSsoUser;
use Misakstvanu\ZssoClient\Events\SsoLoginCompleted;
use Misakstvanu\ZssoClient\Exceptions\ProvisioningDeniedException;
use Misakstvanu\ZssoClient\Http\Controllers\ZssoLoginController;
use Misakstvanu\ZssoClient\Models\ZssoToken;
use Misakstvanu\ZssoClient\SsoUser;
use Misakstvanu\ZssoClient\Tests\Fixtures\ProvisionFixtureUser;
use Misakstvanu\ZssoClient\Tests\Fixtures\User;

beforeEach(function () {
    config([
        'zsso.server_url' => 'https://zsso.test',
        'zsso.client_id' => 'client-id',
        'zsso.client_secret' => 'client-secret',
        'zsso.scopes' => 'profile email',
    ]);

    app()->bind(ProvisionsSsoUser::class, ProvisionFixtureUser::class);

    Http::preventStrayRequests();
});

/**
 * The token endpoint and userinfo an authorization code buys.
 *
 * @param  array<string, mixed>  $token
 * @param  array<string, mixed>  $userinfo
 */
function fakeLoginExchange(array $token = [], array $userinfo = []): void
{
    fakeZssoServer([
        'zsso.test/oauth/token' => Http::response(array_merge([
            'access_token' => 'access-token-1',
            'refresh_token' => 'refresh-token-1',
            'expires_in' => 900,
            'scope' => 'profile email',
            'token_type' => 'Bearer',
        ], $token)),
        'zsso.test/api/userinfo' => Http::response(userinfoPayload($userinfo)),
    ]);
}

/**
 * A callback request with the OAuth state and the PKCE verifier the redirect
 * left in the session.
 *
 * @param  array<string, mixed>  $session
 */
function getLoginCallback(string $query = 'code=auth-code&state=test-state', array $session = [])
{
    return test()
        ->withSession(array_merge(['state' => 'test-state', 'code_verifier' => 'test-verifier'], $session))
        ->get('/auth/zsso/callback?'.$query);
}

it('registers the three login routes in the web group', function () {
    $routes = collect(Route::getRoutes()->getRoutes())
        ->keyBy(fn ($route) => (string) $route->getName());

    expect($routes)->toHaveKeys(['zsso.redirect', 'zsso.callback', 'zsso.logout']);

    expect($routes['zsso.redirect']->uri())->toBe('auth/zsso/redirect')
        ->and($routes['zsso.redirect']->methods())->toContain('GET')
        ->and($routes['zsso.callback']->uri())->toBe('auth/zsso/callback')
        ->and($routes['zsso.logout']->uri())->toBe('auth/zsso/logout')
        ->and($routes['zsso.logout']->methods())->toContain('POST');

    foreach (['zsso.redirect', 'zsso.callback', 'zsso.logout'] as $name) {
        expect($routes[$name]->gatherMiddleware())->toContain('web');
    }
});

it('sends the visitor to the authorize endpoint', function () {
    fakeZssoServer();

    $response = $this->get('/auth/zsso/redirect');

    $location = (string) $response->headers->get('Location');

    expect($location)->toStartWith('https://zsso.test/oauth/authorize?');

    expect(queryOf($location))
        ->toHaveKey('code_challenge')
        ->toHaveKey('state')
        ->and(queryOf($location)['client_id'])->toBe('client-id')
        ->and(queryOf($location)['code_challenge_method'])->toBe('S256')
        ->and(queryOf($location)['scope'])->toBe('profile email')
        ->and(queryOf($location)['redirect_uri'])->toBe('http://localhost/auth/zsso/callback')
        ->and(queryOf($location))->not->toHaveKey('prompt');
});

it('remembers a safe same-origin redirect target', function (string $target) {
    fakeZssoServer();

    $this->get('/auth/zsso/redirect?redirect='.urlencode($target))
        ->assertSessionHas('url.intended', $target);
})->with([
    '/dashboard',
    '/dashboard?tab=members',
    'http://localhost/dashboard',
]);

it('drops a redirect target that leaves this app', function (string $target) {
    fakeZssoServer();

    $this->get('/auth/zsso/redirect?redirect='.urlencode($target))
        ->assertSessionMissing('url.intended');
})->with([
    'https://evil.test/steal',
    '//evil.test/steal',
    '/\\evil.test/steal',
    'dashboard',
    'javascript:alert(1)',
]);

it('asks zSSO not to prompt when a silent login sends the visitor', function () {
    fakeZssoServer();

    $response = $this->get('/auth/zsso/redirect?prompt=none');

    expect(queryOf((string) $response->headers->get('Location'))['prompt'])->toBe('none');
});

it('signs the user in, stores the tokens and follows the intended URL', function () {
    fakeLoginExchange();

    $this->freezeTime();

    getLoginCallback(session: ['url.intended' => '/dashboard'])
        ->assertRedirect('/dashboard');

    $user = User::query()->firstOrFail();

    expect($user->email)->toBe('jana@example.cz');

    $this->assertAuthenticatedAs($user);

    $token = ZssoToken::forUser($user);

    expect($token)->not->toBeNull()
        ->and($token->sub)->toBe('0192a3f4-1111-2222-3333-444455556666')
        ->and($token->access_token)->toBe('access-token-1')
        ->and($token->refresh_token)->toBe('refresh-token-1')
        ->and($token->scopes)->toBe(['profile', 'email'])
        ->and($token->expires_at->toDateTimeString())->toBe(now()->addSeconds(900)->toDateTimeString());

    // Both tokens are encrypted at rest.
    expect(DB::table('zsso_tokens')->value('access_token'))->not->toBe('access-token-1');
});

it('exchanges the code with the PKCE verifier and reads userinfo with the token', function () {
    fakeLoginExchange();

    getLoginCallback();

    Http::assertSent(function ($request) {
        return $request->url() === 'https://zsso.test/oauth/token'
            && $request['grant_type'] === 'authorization_code'
            && $request['code'] === 'auth-code'
            && $request['code_verifier'] === 'test-verifier'
            && $request['client_id'] === 'client-id'
            && $request['client_secret'] === 'client-secret'
            && $request['redirect_uri'] === 'http://localhost/auth/zsso/callback';
    });

    Http::assertSent(function ($request) {
        return $request->url() === 'https://zsso.test/api/userinfo'
            && $request->hasHeader('Authorization', 'Bearer access-token-1');
    });
});

it('announces the finished login with the local user and the SsoUser', function () {
    Event::fake([SsoLoginCompleted::class]);

    fakeLoginExchange();

    getLoginCallback();

    Event::assertDispatchedTimes(SsoLoginCompleted::class, 1);
    Event::assertDispatched(SsoLoginCompleted::class, function (SsoLoginCompleted $event) {
        return $event->user->is(User::query()->firstOrFail())
            && $event->ssoUser->sub === '0192a3f4-1111-2222-3333-444455556666'
            && $event->ssoUser->token === 'access-token-1';
    });
});

it('announces nothing when the login does not finish', function () {
    Event::fake([SsoLoginCompleted::class]);

    fakeLoginExchange();

    app()->bind(ProvisionsSsoUser::class, fn () => new class implements ProvisionsSsoUser
    {
        public function provision(SsoUser $user): Authenticatable
        {
            throw new ProvisioningDeniedException('Nejsi členem tohoto oddílu.');
        }
    });

    getLoginCallback()->assertRedirect('/login');

    Event::assertNotDispatched(SsoLoginCompleted::class);
});

it('hands the SsoUser to the app binding', function () {
    fakeLoginExchange();

    app()->bind(ProvisionsSsoUser::class, fn () => new class implements ProvisionsSsoUser
    {
        public function provision(SsoUser $user): Authenticatable
        {
            expect($user->sub)->toBe('0192a3f4-1111-2222-3333-444455556666')
                ->and($user->name)->toBe('Jana Nováková')
                ->and($user->token)->toBe('access-token-1');

            return User::query()->create([
                'name' => (string) $user->name,
                'email' => 'provisioned@example.cz',
                'password' => 'x',
            ]);
        }
    });

    getLoginCallback();

    $this->assertAuthenticatedAs(User::query()->firstOrFail());

    expect(User::query()->firstOrFail()->email)->toBe('provisioned@example.cz');
});

it('sends a denied provisioning back to the login page with the reason', function () {
    fakeLoginExchange();

    app()->bind(ProvisionsSsoUser::class, fn () => new class implements ProvisionsSsoUser
    {
        public function provision(SsoUser $user): Authenticatable
        {
            throw new ProvisioningDeniedException('Nejsi členem tohoto oddílu.');
        }
    });

    getLoginCallback()
        ->assertRedirect('/login')
        ->assertSessionHas(ZssoLoginController::ERROR_KEY, 'Nejsi členem tohoto oddílu.');

    $this->assertGuest();

    expect(ZssoToken::query()->count())->toBe(0);
});

it('sends an authorization error back to the login page', function () {
    fakeZssoServer();

    getLoginCallback('error=access_denied&error_description=Uživatel+odmítl+přístup.')
        ->assertRedirect('/login')
        ->assertSessionHas(ZssoLoginController::ERROR_KEY, 'Uživatel odmítl přístup.');

    $this->assertGuest();
});

it('lets a silent login that would need the user carry on as a guest', function (string $error) {
    fakeZssoServer();

    getLoginCallback('error='.$error, ['url.intended' => '/dashboard'])
        ->assertRedirect('/dashboard')
        ->assertSessionMissing(ZssoLoginController::ERROR_KEY);

    $this->assertGuest();
})->with(['login_required', 'consent_required', 'interaction_required']);

it('logs out locally, drops the token row and answers with the server logout URL', function () {
    fakeZssoServer();

    $user = User::query()->create(['name' => 'Jana', 'email' => 'jana@example.cz', 'password' => 'x']);

    ZssoToken::storeFor($user, new SsoUser(sub: 'sub-1', token: 'access-token-1'));

    $response = $this->actingAs($user)->postJson('/auth/zsso/logout');

    $response->assertOk()->assertExactJson([
        'redirect' => 'https://zsso.test/logout'
            .'?post_logout_redirect_uri='.urlencode('http://localhost/login')
            .'&client_id=client-id',
    ]);

    $this->assertGuest();

    expect(ZssoToken::query()->count())->toBe(0);
});

it('redirects a non-JSON logout to the server', function () {
    fakeZssoServer();

    $user = User::query()->create(['name' => 'Jana', 'email' => 'jana@example.cz', 'password' => 'x']);

    $this->actingAs($user)->post('/auth/zsso/logout')
        ->assertRedirect('https://zsso.test/logout'
            .'?post_logout_redirect_uri='.urlencode('http://localhost/login')
            .'&client_id=client-id');
});

it('logs a guest out without complaining', function () {
    fakeZssoServer();

    $this->postJson('/auth/zsso/logout')->assertOk();
});
