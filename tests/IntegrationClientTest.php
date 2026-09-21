<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Misakstvanu\ZssoClient\Exceptions\IntegrationRequestException;
use Misakstvanu\ZssoClient\Facades\Zsso;
use Misakstvanu\ZssoClient\Http\Middleware\VerifyIntegrationToken;

beforeEach(function () {
    config([
        'zsso.server_url' => 'https://zsso.test',
        'zsso.client_id' => 'this-app-client-id',
        'zsso.client_secret' => 'this-app-secret',
        'zsso.app_slug' => 'zskauting',
        'zsso.apps' => [],
    ]);
});

/**
 * How often this app asked zSSO for its own client-credentials token.
 */
function clientTokenRequests(): int
{
    return Http::recorded(fn (Request $request) => $request->url() === 'https://zsso.test/oauth/token')->count();
}

/**
 * The calls that reached an app's integration API.
 *
 * @return Collection<int, Request>
 */
function integrationCalls(string $baseUrl = 'https://zirafa.test/api/integration/v1'): Collection
{
    return Http::recorded(fn (Request $request) => str_starts_with($request->url(), $baseUrl))
        ->map(fn (array $pair) => $pair[0])
        ->values();
}

test('a call carries the app integration url, the client token and an accept header', function () {
    fakeIntegrationServer(['zirafa.test/api/integration/v1/*' => Http::response(['data' => []])]);

    $response = Zsso::integration('zirafa')->get('events');

    expect($response->ok())->toBeTrue();

    Http::assertSent(fn (Request $request) => $request->url() === 'https://zirafa.test/api/integration/v1/events'
        && $request->hasHeader('Authorization', 'Bearer this-apps-own-token')
        && $request->hasHeader('Accept', 'application/json'));
});

test('a configured app url wins over the server app list', function () {
    config(['zsso.apps' => ['zirafa' => 'https://zirafa.localhost/api/integration/v1/']]);

    fakeIntegrationServer(['zirafa.localhost/*' => Http::response(['data' => []])]);

    Zsso::integration('zirafa')->get('/events');

    expect(integrationCalls('https://zirafa.localhost/api/integration/v1'))->toHaveCount(1)
        ->and(Http::recorded(fn (Request $request) => $request->url() === 'https://zsso.test/api/apps'))->toHaveCount(0);
});

test('an app row may be configured instead of the url', function () {
    config(['zsso.apps' => ['zirafa' => ['integration_url' => 'https://zirafa.localhost/api/integration/v1']]]);

    fakeIntegrationServer(['zirafa.localhost/*' => Http::response(['data' => []])]);

    Zsso::integration('zirafa')->get('events');

    expect(integrationCalls('https://zirafa.localhost/api/integration/v1'))->toHaveCount(1);
});

test('two calls share one client-credentials token', function () {
    fakeIntegrationServer(['zirafa.test/api/integration/v1/*' => Http::response(['data' => []])]);

    Zsso::integration('zirafa')->get('events');
    Zsso::integration('zirafa')->post('events', ['name' => 'Tábor']);

    expect(integrationCalls())->toHaveCount(2)
        ->and(clientTokenRequests())->toBe(1);
});

test('the client token is minted with the integration scope', function () {
    fakeIntegrationServer(['zirafa.test/api/integration/v1/*' => Http::response(['data' => []])]);

    Zsso::integration('zirafa')->get('events');

    Http::assertSent(fn (Request $request) => $request->url() === 'https://zsso.test/oauth/token'
        && $request['grant_type'] === 'client_credentials'
        && $request['scope'] === 'integration'
        && $request['client_id'] === 'this-app-client-id'
        && $request['client_secret'] === 'this-app-secret');
});

test('acting for a user adds the acting user header', function () {
    fakeIntegrationServer(['zirafa.test/api/integration/v1/*' => Http::response(['data' => []])]);

    Zsso::integration('zirafa')->actingAs('0192a3f4-user-sub')->get('events');

    Http::assertSent(fn (Request $request) => $request->url() === 'https://zirafa.test/api/integration/v1/events'
        && $request->hasHeader(VerifyIntegrationToken::ACTING_USER_HEADER, '0192a3f4-user-sub'));
});

test('a 401 clears the cached token and retries the call once', function () {
    fakeIntegrationServer([
        'zirafa.test/api/integration/v1/*' => Http::sequence()
            ->push(['message' => 'The bearer token was not signed by zSSO.'], 401)
            ->push(['data' => ['ok']]),
        'zsso.test/oauth/token' => Http::sequence()
            ->push(['access_token' => 'stale-token', 'expires_in' => 3600])
            ->push(['access_token' => 'fresh-token', 'expires_in' => 3600]),
    ]);

    $response = Zsso::integration('zirafa')->get('events');

    expect($response->json('data'))->toBe(['ok'])
        ->and(clientTokenRequests())->toBe(2);

    $calls = integrationCalls();

    expect($calls)->toHaveCount(2)
        ->and($calls[0]->hasHeader('Authorization', 'Bearer stale-token'))->toBeTrue()
        ->and($calls[1]->hasHeader('Authorization', 'Bearer fresh-token'))->toBeTrue();
});

test('the retry keeps everything the call was built with', function () {
    fakeIntegrationServer([
        'zirafa.test/api/integration/v1/*' => Http::sequence()
            ->push(['message' => 'Unauthenticated.'], 401)
            ->push(['data' => []]),
    ]);

    Zsso::integration('zirafa')->actingAs('0192a3f4-user-sub')->withHeaders(['X-Trace' => 'abc'])->get('events');

    expect(integrationCalls()->every(fn (Request $request) => $request->hasHeader(VerifyIntegrationToken::ACTING_USER_HEADER, '0192a3f4-user-sub')
        && $request->hasHeader('X-Trace', 'abc')))->toBeTrue();
});

test('a second 401 is not retried again and is thrown', function () {
    fakeIntegrationServer([
        'zirafa.test/api/integration/v1/*' => Http::response(['message' => 'Unauthenticated.'], 401),
    ]);

    expect(fn () => Zsso::integration('zirafa')->get('events'))
        ->toThrow(function (IntegrationRequestException $exception) {
            expect($exception->app)->toBe('zirafa')
                ->and($exception->response?->status())->toBe(401)
                ->and($exception->getMessage())->toBe('The integration call to [zirafa] answered 401.');
        });

    expect(integrationCalls())->toHaveCount(2);
});

test('any other error status is thrown with the response', function () {
    fakeIntegrationServer([
        'zirafa.test/api/integration/v1/*' => Http::response(['message' => 'Zadaná data nejsou platná.'], 422),
    ]);

    expect(fn () => Zsso::integration('zirafa')->post('events', ['name' => '']))
        ->toThrow(function (IntegrationRequestException $exception) {
            expect($exception->response?->status())->toBe(422)
                ->and($exception->response?->json('message'))->toBe('Zadaná data nejsou platná.')
                ->and($exception->getCode())->toBe(422);
        });

    expect(integrationCalls())->toHaveCount(1);
});

test('an app the server does not list cannot be called', function () {
    fakeIntegrationServer();

    expect(fn () => Zsso::integration('nekdo-jiny'))
        ->toThrow(IntegrationRequestException::class, 'zSSO knows no app [nekdo-jiny] with an integration URL.');
});

test('an app without an integration url cannot be called', function () {
    fakeIntegrationServer(apps: [[
        'slug' => 'zirafa',
        'client_id' => 'zirafa-client-id',
        'name' => 'Žirafa',
        'base_url' => 'https://zirafa.test',
        'integration_url' => null,
    ]]);

    expect(fn () => Zsso::integration('zirafa'))->toThrow(IntegrationRequestException::class);
});

test('a call zsso will not mint a token for is refused before it is made', function () {
    fakeIntegrationServer([
        'zsso.test/oauth/token' => Http::response(['error' => 'invalid_client'], 401),
    ]);

    config(['zsso.apps' => ['zirafa' => 'https://zirafa.test/api/integration/v1']]);

    expect(fn () => Zsso::integration('zirafa')->get('events'))
        ->toThrow(IntegrationRequestException::class, 'zSSO would not mint a client-credentials token for the call to [zirafa].');

    expect(integrationCalls())->toHaveCount(0);
});
