<?php

use Illuminate\Support\Facades\Route;
use Misakstvanu\ZssoClient\Http\Middleware\EnsureSilentLoginAttempted;
use Misakstvanu\ZssoClient\Tests\Fixtures\User;
use Symfony\Component\HttpFoundation\Cookie;

beforeEach(function () {
    config([
        'zsso.server_url' => 'https://zsso.test',
        'zsso.client_id' => 'client-id',
        'zsso.client_secret' => 'client-secret',
    ]);

    Route::middleware(['web', 'zsso.silent'])->group(function () {
        Route::get('/dashboard', fn () => 'dashboard');
        Route::get('/login', fn () => 'login page');
        Route::post('/dashboard', fn () => 'posted');
    });

    Route::middleware('zsso.silent')->get('/api/ping', fn () => 'pong');
});

/**
 * The `zsso_silent` cookie a response sets, if it sets one.
 */
function silentCookie(mixed $response): ?Cookie
{
    foreach ($response->baseResponse->headers->getCookies() as $cookie) {
        if ($cookie->getName() === EnsureSilentLoginAttempted::COOKIE) {
            return $cookie;
        }
    }

    return null;
}

it('sends a guest to the server once with prompt=none and remembers it in a cookie', function () {
    $response = $this->get('/dashboard?tab=today');

    $response->assertRedirect();

    $target = $response->headers->get('Location');

    expect(parse_url((string) $target, PHP_URL_PATH))->toBe('/auth/zsso/redirect')
        ->and(queryOf((string) $target))->toBe([
            'redirect' => url('/dashboard?tab=today'),
            'prompt' => 'none',
        ]);

    $cookie = silentCookie($response);

    expect($cookie)->not->toBeNull()
        ->and($cookie->getValue())->not->toBeEmpty()
        ->and($cookie->getExpiresTime())->toBeGreaterThan(time())
        ->and($cookie->getExpiresTime())->toBeLessThanOrEqual(time() + EnsureSilentLoginAttempted::COOKIE_MINUTES * 60);
});

it('lets a guest through once the cookie is there', function () {
    $this->withCookie(EnsureSilentLoginAttempted::COOKIE, '1')
        ->get('/dashboard')
        ->assertOk()
        ->assertSee('dashboard');
});

it('lets an authenticated user through', function () {
    $user = User::query()->create(['name' => 'Jana', 'email' => 'jana@example.cz', 'password' => 'x']);

    $response = $this->actingAs($user)->get('/dashboard');

    $response->assertOk();

    expect(silentCookie($response))->toBeNull();
});

it('leaves API paths alone', function () {
    $response = $this->get('/api/ping', ['Accept' => 'text/html']);

    $response->assertOk()->assertSee('pong');

    expect(silentCookie($response))->toBeNull();
});

it('leaves the app login page alone', function () {
    $this->get('/login')->assertOk()->assertSee('login page');
});

it('leaves the package login routes alone', function () {
    // The middleware firing on `/auth/zsso/redirect` would be a loop: that
    // route is where it sends the visitor.
    fakeZssoServer();

    $response = $this->get('/auth/zsso/redirect');

    expect($response->headers->get('Location'))->toStartWith('https://zsso.test/oauth/authorize?');
});

it('only fires on a GET request that accepts HTML', function () {
    $this->post('/dashboard')->assertOk()->assertSee('posted');

    $this->getJson('/dashboard')->assertOk();
});

it('does nothing when silent login is turned off', function () {
    config(['zsso.silent_login' => false]);

    $response = $this->get('/dashboard');

    $response->assertOk()->assertSee('dashboard');

    expect(silentCookie($response))->toBeNull();
});

it('keeps the cookie when the server answers login_required', function () {
    // The callback of a silent login: the visitor stays a guest and lands on
    // the URL they asked for, with the cookie left in place so the middleware
    // does not send them straight back.
    $response = $this
        ->withCookie(EnsureSilentLoginAttempted::COOKIE, '1')
        ->withSession(['url.intended' => url('/dashboard?tab=today')])
        ->get('/auth/zsso/callback?error=login_required&state=test-state');

    $response->assertRedirect(url('/dashboard?tab=today'));

    expect(silentCookie($response))->toBeNull();

    $this->assertGuest();

    $this->withCookie(EnsureSilentLoginAttempted::COOKIE, '1')
        ->get('/dashboard?tab=today')
        ->assertOk()
        ->assertSee('dashboard');
});
