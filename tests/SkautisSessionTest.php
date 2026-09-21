<?php

use Illuminate\Support\Facades\Http;
use Misakstvanu\ZssoClient\Exceptions\SessionLostException;
use Misakstvanu\ZssoClient\Exceptions\SkautisRequestException;
use Misakstvanu\ZssoClient\Exceptions\SkautisUnavailableException;
use Misakstvanu\ZssoClient\Facades\Zsso;
use Misakstvanu\ZssoClient\Models\ZssoToken;
use Misakstvanu\ZssoClient\SkautisSessionData;
use Misakstvanu\ZssoClient\Tests\Fixtures\User;
use Skautis\Config;
use Skautis\Skautis;
use Skautis\User as SkautisUser;
use Skautis\Wsdl\WebServiceFactory;
use Skautis\Wsdl\WsdlManager;

beforeEach(function () {
    config([
        'zsso.server_url' => 'https://zsso.test',
        'zsso.client_id' => 'client-id',
        'zsso.client_secret' => 'client-secret',
    ]);

    Http::preventStrayRequests();
});

/**
 * A locally signed-in user this app already holds a live zSSO token for.
 */
function skautisSessionUser(): User
{
    $user = User::query()->create(['name' => 'Jana', 'email' => 'jana@example.cz', 'password' => 'x']);

    ZssoToken::query()->create([
        'user_id' => (string) $user->getKey(),
        'sub' => 'sub-1',
        'access_token' => 'access-token-1',
        'refresh_token' => 'refresh-token-1',
        'expires_at' => now()->addMinutes(10),
        'scopes' => ['profile', 'email', 'skautis', 'skautis:session'],
    ]);

    return $user;
}

/**
 * The C-5 session payload.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function skautisSessionPayload(array $overrides = []): array
{
    return array_merge([
        'login_id' => 'soap-token-1',
        'role_id' => 678,
        'unit_id' => 910,
        'logout_at' => '2026-09-08T14:35:00+02:00',
        'user_id' => 12345,
    ], $overrides);
}

/**
 * A `skautis/skautis` client that never talks to SOAP: the WSDL manager only
 * builds a web service when one is asked for, and the login data lives on the
 * user object alone.
 */
function skautisClient(): Skautis
{
    $wsdl = new WsdlManager(new WebServiceFactory, new Config('test-app-id', true));

    return new Skautis($wsdl, new SkautisUser($wsdl));
}

it('reads the session zSSO holds for the user', function () {
    $user = skautisSessionUser();

    fakeZssoServer([
        'zsso.test/api/skautis/session' => Http::response(skautisSessionPayload()),
    ]);

    $session = Zsso::skautis($user)->session();

    expect($session)->toBeInstanceOf(SkautisSessionData::class)
        ->and($session->loginId)->toBe('soap-token-1')
        ->and($session->roleId)->toBe(678)
        ->and($session->unitId)->toBe(910)
        ->and($session->userId)->toBe(12345)
        ->and($session->logoutAt?->format(DATE_ATOM))->toBe('2026-09-08T14:35:00+02:00');

    Http::assertSent(fn ($request) => $request->url() === 'https://zsso.test/api/skautis/session'
        && $request->method() === 'GET'
        && $request->hasHeader('Authorization', 'Bearer access-token-1'));
});

it('keeps the session alive', function () {
    $user = skautisSessionUser();

    fakeZssoServer([
        'zsso.test/api/skautis/refresh' => Http::response(skautisSessionPayload([
            'logout_at' => '2026-09-08T15:05:00+02:00',
        ])),
    ]);

    expect(Zsso::skautis($user)->refresh()?->logoutAt?->format(DATE_ATOM))
        ->toBe('2026-09-08T15:05:00+02:00');

    Http::assertSent(fn ($request) => $request->url() === 'https://zsso.test/api/skautis/refresh'
        && $request->method() === 'POST');
});

it('switches the active role', function () {
    $user = skautisSessionUser();

    fakeZssoServer([
        'zsso.test/api/skautis/role' => Http::response(skautisSessionPayload([
            'role_id' => 679,
            'unit_id' => 911,
        ])),
    ]);

    $session = Zsso::skautis($user)->switchRole(679);

    expect($session?->roleId)->toBe(679)
        ->and($session?->unitId)->toBe(911);

    Http::assertSent(fn ($request) => $request->url() === 'https://zsso.test/api/skautis/role'
        && $request->method() === 'POST'
        && $request->data() === ['role_id' => 679]);
});

it('logs the session out', function () {
    $user = skautisSessionUser();

    fakeZssoServer([
        'zsso.test/api/skautis/logout' => Http::response(status: 204),
    ]);

    expect(Zsso::skautis($user)->logout())->toBeTrue();

    Http::assertSent(fn ($request) => $request->url() === 'https://zsso.test/api/skautis/logout'
        && $request->method() === 'POST'
        && $request->hasHeader('Authorization', 'Bearer access-token-1'));
});

it('reads a user without a SkautIS session as null', function () {
    $user = skautisSessionUser();

    fakeZssoServer([
        'zsso.test/api/skautis/*' => Http::response(['message' => 'Uživatel nemá aktivní SkautIS session.'], 404),
    ]);

    $skautis = Zsso::skautis($user);

    expect($skautis->session())->toBeNull()
        ->and($skautis->refresh())->toBeNull()
        ->and($skautis->switchRole(679))->toBeNull()
        ->and($skautis->logout())->toBeFalse();
});

it('reports a SkautIS outage as such', function () {
    $user = skautisSessionUser();

    fakeZssoServer([
        'zsso.test/api/skautis/refresh' => Http::response(['message' => 'SkautIS neodpověděl. Zkuste to znovu.'], 503),
    ]);

    Zsso::skautis($user)->refresh();
})->throws(SkautisUnavailableException::class, 'SkautIS did not answer zSSO; the session was left as it was.');

it('carries the server\'s answer on any other failure', function () {
    $user = skautisSessionUser();

    fakeZssoServer([
        'zsso.test/api/skautis/session' => Http::response(['message' => 'Invalid scope(s) provided.'], 403),
    ]);

    try {
        Zsso::skautis($user)->session();
    } catch (SkautisRequestException $exception) {
        expect($exception->getCode())->toBe(403)
            ->and($exception->response?->json('message'))->toBe('Invalid scope(s) provided.');

        return;
    }

    $this->fail('The 403 did not raise a SkautisRequestException.');
});

it('refreshes a spent access token before the call', function () {
    $user = skautisSessionUser();

    ZssoToken::forUser($user)?->forceFill(['expires_at' => now()->subMinute()])->save();

    fakeZssoServer([
        'zsso.test/oauth/token' => Http::response([
            'access_token' => 'access-token-2',
            'refresh_token' => 'refresh-token-2',
            'expires_in' => 900,
            'scope' => 'profile email skautis skautis:session',
            'token_type' => 'Bearer',
        ]),
        'zsso.test/api/skautis/session' => Http::response(skautisSessionPayload()),
    ]);

    expect(Zsso::skautis($user)->session()?->loginId)->toBe('soap-token-1');

    Http::assertSent(fn ($request) => $request->url() === 'https://zsso.test/api/skautis/session'
        && $request->hasHeader('Authorization', 'Bearer access-token-2'));
});

it('cannot act for a user this app holds no token for', function () {
    $user = User::query()->create(['name' => 'Petr', 'email' => 'petr@example.cz', 'password' => 'x']);

    Zsso::skautis($user)->session();
})->throws(SessionLostException::class);

it('hydrates a SkautIS SOAP client from the session', function () {
    $session = SkautisSessionData::fromArray(skautisSessionPayload());
    $skautis = skautisClient();

    $session->applyTo($skautis);

    expect($skautis->getUser()->getLoginId())->toBe('soap-token-1')
        ->and($skautis->getUser()->getRoleId())->toBe(678)
        ->and($skautis->getUser()->getUnitId())->toBe(910)
        ->and($skautis->getUser()->getLogoutDate())->toBeInstanceOf(DateTime::class)
        ->and($skautis->getUser()->getLogoutDate()->format(DATE_ATOM))->toBe('2026-09-08T14:35:00+02:00');
});

it('reads a session without a role, unit or logout time', function () {
    $session = SkautisSessionData::fromArray(['login_id' => 'soap-token-1', 'user_id' => 12345]);

    expect($session->roleId)->toBeNull()
        ->and($session->unitId)->toBeNull()
        ->and($session->logoutAt)->toBeNull()
        ->and($session->isActive())->toBeFalse();

    $skautis = skautisClient();
    $session->applyTo($skautis);

    expect($skautis->getUser()->getLoginId())->toBe('soap-token-1')
        ->and($skautis->getUser()->getRoleId())->toBeNull()
        ->and($skautis->getUser()->getLogoutDate())->toBeNull();
});

it('knows whether SkautIS still holds the login', function () {
    expect(SkautisSessionData::fromArray(skautisSessionPayload([
        'logout_at' => now()->addMinutes(20)->toIso8601String(),
    ]))->isActive())->toBeTrue();

    expect(SkautisSessionData::fromArray(skautisSessionPayload([
        'logout_at' => now()->subMinute()->toIso8601String(),
    ]))->isActive())->toBeFalse();
});

it('falls back to the conventional C-5 paths when discovery is down', function () {
    $user = skautisSessionUser();

    Http::fake([
        'zsso.test/.well-known/openid-configuration' => Http::response(status: 500),
        'zsso.test/api/skautis/session' => Http::response(skautisSessionPayload()),
    ]);

    expect(Zsso::skautis($user)->session()?->userId)->toBe(12345);
});

it('follows the endpoints the discovery document names', function () {
    $user = skautisSessionUser();

    Http::fake([
        'zsso.test/.well-known/openid-configuration' => Http::response(discoveryDocument([
            'skautis_session_endpoint' => 'https://api.zsso.test/skautis/me',
        ])),
        'api.zsso.test/skautis/me' => Http::response(skautisSessionPayload()),
    ]);

    expect(Zsso::skautis($user)->session()?->loginId)->toBe('soap-token-1');
});
