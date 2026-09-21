<?php

use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
        'zsso.server_url' => 'https://zsso.test',
        'zsso.client_id' => 'this-app-client-id',
        'zsso.client_secret' => 'this-app-secret',
        'zsso.app_slug' => 'zskauting',
    ]);
});

it('fails while the client credentials are missing', function () {
    config(['zsso.server_url' => null, 'zsso.client_secret' => null]);

    $this->artisan('zsso:doctor')
        ->expectsOutputToContain('zsso.server_url is not set')
        ->expectsOutputToContain('zsso.client_secret is not set')
        ->expectsOutputToContain('not checked, the configuration is incomplete')
        ->assertExitCode(1);

    Http::assertNothingSent();
});

it('passes once the server answers every check', function () {
    fakeIntegrationServer();

    $this->artisan('zsso:doctor')
        ->expectsOutputToContain('discovery document')
        ->expectsOutputToContain('signing keys')
        ->expectsOutputToContain('client-credentials token')
        ->assertExitCode(0);
});

it('names the endpoint that did not answer with a discovery document', function () {
    Http::fake([
        'zsso.test/.well-known/openid-configuration' => Http::response(status: 500),
        'zsso.test/.well-known/jwks.json' => Http::response(integrationJwks()),
        'zsso.test/oauth/token' => Http::response([
            'access_token' => 'this-apps-own-token',
            'expires_in' => 3600,
        ]),
    ]);

    $this->artisan('zsso:doctor')
        ->expectsOutputToContain('https://zsso.test/.well-known/openid-configuration did not answer with one')
        ->assertExitCode(1);
});

it('reports a JWK set with no usable key', function () {
    fakeIntegrationServer(['zsso.test/.well-known/jwks.json' => Http::response(['keys' => []])]);

    $this->artisan('zsso:doctor')
        ->expectsOutputToContain('https://zsso.test/.well-known/jwks.json published no usable RSA key')
        ->assertExitCode(1);
});

it('reports a token endpoint that mints nothing', function () {
    fakeIntegrationServer(['zsso.test/oauth/token' => Http::response(['error' => 'invalid_client'], 401)]);

    $this->artisan('zsso:doctor')
        ->expectsOutputToContain('https://zsso.test/oauth/token minted none')
        ->assertExitCode(1);
});

it('checks the server rather than the cached answers', function () {
    fakeIntegrationServer();

    $this->artisan('zsso:doctor')->assertExitCode(0);
    $this->artisan('zsso:doctor')->assertExitCode(0);

    $requests = collect(Http::recorded())->map(fn (array $pair): string => $pair[0]->url());

    expect($requests->filter(fn (string $url): bool => str_contains($url, 'openid-configuration')))->toHaveCount(2)
        ->and($requests->filter(fn (string $url): bool => str_contains($url, 'jwks.json')))->toHaveCount(2)
        ->and($requests->filter(fn (string $url): bool => str_contains($url, 'oauth/token')))->toHaveCount(2);
});
