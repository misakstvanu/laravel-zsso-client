<?php

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Misakstvanu\ZssoClient\Http\Middleware\VerifyIntegrationToken;
use Misakstvanu\ZssoClient\IntegrationContext;

beforeEach(function () {
    config([
        'zsso.server_url' => 'https://zsso.test',
        'zsso.client_id' => 'this-app-client-id',
        'zsso.client_secret' => 'this-app-secret',
        'zsso.app_slug' => 'zskauting',
    ]);

    Route::middleware('zsso.integration')->get('/api/integration/v1/ping', fn () => response()->json([
        'caller' => IntegrationContext::current()?->callerSlug,
        'acting_user' => IntegrationContext::current()?->actingUserSub,
        'acts_for_user' => IntegrationContext::current()?->actsForUser(),
    ]));

    Route::middleware('zsso.integration:acting-user')->get('/api/integration/v1/mine', fn () => response()->json([
        'acting_user' => IntegrationContext::current()?->actingUserSub,
    ]));
});

test('a valid token names the calling app on the request', function () {
    fakeIntegrationServer();

    $this->withToken(integrationToken())
        ->getJson('/api/integration/v1/ping')
        ->assertOk()
        ->assertExactJson([
            'caller' => 'zirafa',
            'acting_user' => null,
            'acts_for_user' => false,
        ]);
});

test('the acting user header lands in the context', function () {
    fakeIntegrationServer();

    $this->withToken(integrationToken())
        ->withHeader(VerifyIntegrationToken::ACTING_USER_HEADER, '0192a3f4-user-sub')
        ->getJson('/api/integration/v1/ping')
        ->assertOk()
        ->assertJson([
            'caller' => 'zirafa',
            'acting_user' => '0192a3f4-user-sub',
            'acts_for_user' => true,
        ]);
});

test('a request without a bearer token is refused', function () {
    fakeIntegrationServer();

    $this->getJson('/api/integration/v1/ping')
        ->assertUnauthorized()
        ->assertJson(['message' => 'The request carries no readable zSSO bearer token.']);

    Http::assertNothingSent();
});

test('a bearer token that is not a JWT is refused', function () {
    fakeIntegrationServer();

    $this->withToken('not-a-jwt')
        ->getJson('/api/integration/v1/ping')
        ->assertUnauthorized()
        ->assertJson(['message' => 'The request carries no readable zSSO bearer token.']);
});

test('an expired token is refused', function () {
    fakeIntegrationServer();

    $this->withToken(integrationToken(expiresAt: new DateTimeImmutable('-5 minutes')))
        ->getJson('/api/integration/v1/ping')
        ->assertUnauthorized()
        ->assertJson(['message' => 'The bearer token has expired.']);
});

test('a token without the integration scope is refused', function () {
    fakeIntegrationServer();

    $this->withToken(integrationToken(scopes: ['profile', 'email']))
        ->getJson('/api/integration/v1/ping')
        ->assertUnauthorized()
        ->assertJson(['message' => 'The bearer token does not carry the [integration] scope.']);
});

test('a token signed by another key is refused', function () {
    fakeIntegrationServer();

    openssl_pkey_export(openssl_pkey_new([
        'private_key_bits' => 2048,
        'private_key_type' => OPENSSL_KEYTYPE_RSA,
    ]), $otherKey);

    $this->withToken(integrationToken(privateKey: (string) $otherKey))
        ->getJson('/api/integration/v1/ping')
        ->assertUnauthorized()
        ->assertJson(['message' => 'The bearer token was not signed by zSSO.']);
});

test('a token issued to an app the server does not list is refused', function () {
    fakeIntegrationServer();

    $this->withToken(integrationToken(clientId: 'a-client-nobody-registered'))
        ->getJson('/api/integration/v1/ping')
        ->assertUnauthorized()
        ->assertJson(['message' => 'The bearer token belongs to an app zSSO does not know.']);
});

test('the acting-user parameter makes the header mandatory', function () {
    fakeIntegrationServer();

    $this->withToken(integrationToken())
        ->getJson('/api/integration/v1/mine')
        ->assertForbidden()
        ->assertJson(['message' => 'This endpoint needs the X-Zsso-Acting-User header.']);

    $this->withToken(integrationToken())
        ->withHeader(VerifyIntegrationToken::ACTING_USER_HEADER, '0192a3f4-user-sub')
        ->getJson('/api/integration/v1/mine')
        ->assertOk()
        ->assertExactJson(['acting_user' => '0192a3f4-user-sub']);
});

test('a blank acting user header counts as absent', function () {
    fakeIntegrationServer();

    $this->withToken(integrationToken())
        ->withHeader(VerifyIntegrationToken::ACTING_USER_HEADER, '   ')
        ->getJson('/api/integration/v1/mine')
        ->assertForbidden();
});

test('a token naming a kid the cached set does not hold re-fetches the keys once', function () {
    Http::fake([
        'zsso.test/.well-known/openid-configuration' => Http::response(discoveryDocument()),
        'zsso.test/.well-known/jwks.json' => Http::sequence()
            ->push(integrationJwks(kid: 'a-key-that-has-been-rotated-away'))
            ->push(integrationJwks()),
        'zsso.test/oauth/token' => Http::response([
            'access_token' => 'this-apps-own-token',
            'expires_in' => 3600,
        ]),
        'zsso.test/api/apps' => Http::response(['data' => [
            ['slug' => 'zirafa', 'client_id' => 'zirafa-client-id'],
        ]]),
    ]);

    $pair = integrationKeyPair();

    $this->withToken(integrationToken(kid: $pair['kid']))
        ->getJson('/api/integration/v1/ping')
        ->assertOk()
        ->assertJson(['caller' => 'zirafa']);

    $jwksCalls = collect(Http::recorded())
        ->filter(fn (array $pair): bool => str_contains($pair[0]->url(), 'jwks.json'))
        ->count();

    expect($jwksCalls)->toBe(2);
});

test('the keys and the app list are cached, so a second call talks to nobody', function () {
    fakeIntegrationServer();

    $this->withToken(integrationToken())->getJson('/api/integration/v1/ping')->assertOk();

    $sent = count(Http::recorded());

    $this->withToken(integrationToken())->getJson('/api/integration/v1/ping')->assertOk();

    expect(count(Http::recorded()))->toBe($sent);
});

test('a client id the cached list does not hold re-fetches the app list once', function () {
    Http::fake([
        'zsso.test/.well-known/openid-configuration' => Http::response(discoveryDocument()),
        'zsso.test/.well-known/jwks.json' => Http::response(integrationJwks()),
        'zsso.test/oauth/token' => Http::response([
            'access_token' => 'this-apps-own-token',
            'expires_in' => 3600,
        ]),
        'zsso.test/api/apps' => Http::sequence()
            ->push(['data' => [['slug' => 'zebra', 'client_id' => 'zebra-client-id']]])
            ->push(['data' => [['slug' => 'zirafa', 'client_id' => 'zirafa-client-id']]]),
    ]);

    $this->withToken(integrationToken())
        ->getJson('/api/integration/v1/ping')
        ->assertOk()
        ->assertJson(['caller' => 'zirafa']);
});

test('the context is null outside a request the middleware handled', function () {
    expect(IntegrationContext::current())->toBeNull();
});
