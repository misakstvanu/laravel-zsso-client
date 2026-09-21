<?php

use Illuminate\Support\Facades\Http;
use Misakstvanu\ZssoClient\Exceptions\SessionLostException;
use Misakstvanu\ZssoClient\Facades\Zsso;
use Misakstvanu\ZssoClient\Models\ZssoToken;
use Misakstvanu\ZssoClient\SsoUser;
use Misakstvanu\ZssoClient\Tests\Fixtures\User;

beforeEach(function () {
    config([
        'zsso.server_url' => 'https://zsso.test',
        'zsso.client_id' => 'client-id',
        'zsso.client_secret' => 'client-secret',
    ]);

    Http::preventStrayRequests();
});

/**
 * A local user this app already holds tokens for.
 *
 * @param  array<string, mixed>  $token
 */
function userWithZssoToken(array $token = []): User
{
    $user = User::query()->create(['name' => 'Jana', 'email' => 'jana@example.cz', 'password' => 'x']);

    ZssoToken::query()->create(array_merge([
        'user_id' => (string) $user->getKey(),
        'sub' => 'sub-1',
        'access_token' => 'access-token-1',
        'refresh_token' => 'refresh-token-1',
        'expires_at' => now()->addMinutes(10),
        'scopes' => ['profile', 'email'],
    ], $token));

    return $user;
}

it('returns the stored access token while it is live', function () {
    $user = userWithZssoToken();

    expect(Zsso::tokenFor($user))->toBe('access-token-1');

    Http::assertNothingSent();
});

it('refreshes a spent access token and keeps the row in sync', function () {
    $this->freezeTime();

    $user = userWithZssoToken(['expires_at' => now()->subMinute()]);

    fakeZssoServer([
        'zsso.test/oauth/token' => Http::response([
            'access_token' => 'access-token-2',
            'refresh_token' => 'refresh-token-2',
            'expires_in' => 900,
            'scope' => 'profile email skautis',
            'token_type' => 'Bearer',
        ]),
    ]);

    expect(Zsso::tokenFor($user))->toBe('access-token-2');

    Http::assertSent(function ($request) {
        return $request->url() === 'https://zsso.test/oauth/token'
            && $request['grant_type'] === 'refresh_token'
            && $request['refresh_token'] === 'refresh-token-1'
            && $request['client_id'] === 'client-id'
            && $request['client_secret'] === 'client-secret';
    });

    $token = ZssoToken::forUser($user);

    expect($token->access_token)->toBe('access-token-2')
        ->and($token->refresh_token)->toBe('refresh-token-2')
        ->and($token->scopes)->toBe(['profile', 'email', 'skautis'])
        ->and($token->expires_at->toDateTimeString())->toBe(now()->addSeconds(900)->toDateTimeString());
});

it('refreshes a token that is about to expire', function () {
    $user = userWithZssoToken(['expires_at' => now()->addSeconds(30)]);

    fakeZssoServer([
        'zsso.test/oauth/token' => Http::response([
            'access_token' => 'access-token-2',
            'expires_in' => 900,
        ]),
    ]);

    expect(Zsso::tokenFor($user))->toBe('access-token-2');

    // The refresh answered without one, so the stored refresh token stays.
    expect(ZssoToken::forUser($user)->refresh_token)->toBe('refresh-token-1');
});

it('throws when this app holds no token for the user', function () {
    $user = User::query()->create(['name' => 'Jana', 'email' => 'jana@example.cz', 'password' => 'x']);

    Zsso::tokenFor($user);
})->throws(SessionLostException::class, 'This app holds no zSSO token for the user; they have to sign in again.');

it('throws when zSSO refuses the refresh token', function () {
    $user = userWithZssoToken(['expires_at' => now()->subMinute()]);

    fakeZssoServer([
        'zsso.test/oauth/token' => Http::response(['error' => 'invalid_grant'], 400),
    ]);

    Zsso::tokenFor($user);
})->throws(SessionLostException::class, 'zSSO refused the refresh token; the user has to sign in again.');

it('throws when the spent token has no refresh token', function () {
    $user = userWithZssoToken(['expires_at' => now()->subMinute(), 'refresh_token' => null]);

    Zsso::tokenFor($user);
})->throws(SessionLostException::class);

it('never expires a token zSSO gave no expiry for', function () {
    $user = userWithZssoToken(['expires_at' => null]);

    expect(Zsso::tokenFor($user))->toBe('access-token-1');

    Http::assertNothingSent();
});

it('stores what a login exchanged', function () {
    $this->freezeTime();

    $user = User::query()->create(['name' => 'Jana', 'email' => 'jana@example.cz', 'password' => 'x']);

    $stored = ZssoToken::storeFor($user, new SsoUser(
        sub: 'sub-1',
        token: 'access-token-1',
        refreshToken: 'refresh-token-1',
        expiresIn: 900,
        scopes: ['profile', 'email'],
    ));

    expect($stored->getKey())->toBe((string) $user->getKey())
        ->and(ZssoToken::query()->count())->toBe(1);

    // A second login replaces the row instead of adding one.
    ZssoToken::storeFor($user, new SsoUser(sub: 'sub-1', token: 'access-token-2'));

    expect(ZssoToken::query()->count())->toBe(1)
        ->and(ZssoToken::forUser($user)->access_token)->toBe('access-token-2')
        ->and(ZssoToken::forUser($user)->expires_at)->toBeNull();
});
