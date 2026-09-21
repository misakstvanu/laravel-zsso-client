<?php

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Misakstvanu\ZssoClient\Discovery;

beforeEach(function () {
    config(['zsso.server_url' => 'https://zsso.test', 'zsso.cache_ttl' => 3600]);
});

it('reads every endpoint from the discovery document', function () {
    Http::fake([
        'zsso.test/.well-known/openid-configuration' => Http::response(discoveryDocument([
            'authorization_endpoint' => 'https://login.zsso.test/authorize',
            'userinfo_endpoint' => 'https://api.zsso.test/me',
        ])),
    ]);

    $discovery = new Discovery;

    expect($discovery->url('authorization_endpoint'))->toBe('https://login.zsso.test/authorize')
        ->and($discovery->url('userinfo_endpoint'))->toBe('https://api.zsso.test/me')
        ->and($discovery->url('token_endpoint'))->toBe('https://zsso.test/oauth/token')
        ->and($discovery->url('jwks_uri'))->toBe('https://zsso.test/.well-known/jwks.json')
        ->and($discovery->url('end_session_endpoint'))->toBe('https://zsso.test/logout')
        ->and($discovery->url('apps_endpoint'))->toBe('https://zsso.test/api/apps')
        ->and($discovery->issuer())->toBe('https://zsso.test');
});

it('fetches the document once and serves later instances from the cache', function () {
    Http::fake(['zsso.test/*' => Http::response(discoveryDocument())]);

    (new Discovery)->url('token_endpoint');
    (new Discovery)->url('token_endpoint');
    (new Discovery)->url('userinfo_endpoint');

    Http::assertSentCount(1);
    expect(Cache::get('zsso.discovery.'.sha1('https://zsso.test')))->toBe(discoveryDocument());
});

it('falls back to the conventional paths when discovery fails', function () {
    Http::fake(['zsso.test/*' => Http::response('nope', 503)]);

    $discovery = new Discovery;

    expect($discovery->url('authorization_endpoint'))->toBe('https://zsso.test/oauth/authorize')
        ->and($discovery->url('token_endpoint'))->toBe('https://zsso.test/oauth/token')
        ->and($discovery->url('userinfo_endpoint'))->toBe('https://zsso.test/api/userinfo')
        ->and($discovery->url('jwks_uri'))->toBe('https://zsso.test/.well-known/jwks.json')
        ->and($discovery->url('end_session_endpoint'))->toBe('https://zsso.test/logout')
        ->and($discovery->url('apps_endpoint'))->toBe('https://zsso.test/api/apps')
        ->and($discovery->document())->toBe([]);
});

it('falls back when the server cannot be reached at all', function () {
    Http::fake(fn () => throw new ConnectionException('Could not resolve host.'));

    expect((new Discovery)->url('token_endpoint'))->toBe('https://zsso.test/oauth/token');
});

it('does not cache a failed fetch', function () {
    Http::fakeSequence('zsso.test/*')->push('nope', 503)->push(discoveryDocument());

    expect((new Discovery)->document())->toBe([])
        ->and(Cache::has('zsso.discovery.'.sha1('https://zsso.test')))->toBeFalse()
        ->and((new Discovery)->document())->toBe(discoveryDocument());
});

it('drops the memoised and the cached document on forget', function () {
    Http::fake(['zsso.test/*' => Http::response(discoveryDocument())]);

    $discovery = new Discovery;
    $discovery->document();
    $discovery->forget();
    $discovery->document();

    Http::assertSentCount(2);
});

it('refuses an endpoint it knows no conventional path for', function () {
    Http::fake(['zsso.test/*' => Http::response(discoveryDocument(['registration_endpoint' => 'https://zsso.test/register']))]);

    (new Discovery)->url('registration_endpoint');
})->throws(InvalidArgumentException::class, 'The zSSO discovery document has no endpoint [registration_endpoint].');

it('trims a trailing slash off the configured server url', function () {
    config(['zsso.server_url' => 'https://zsso.test/']);
    Http::fake(['zsso.test/*' => Http::response('', 503)]);

    expect((new Discovery)->issuer())->toBe('https://zsso.test')
        ->and((new Discovery)->url('token_endpoint'))->toBe('https://zsso.test/oauth/token');
});
