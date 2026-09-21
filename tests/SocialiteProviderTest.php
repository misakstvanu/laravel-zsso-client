<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Socialite\Facades\Socialite;
use Misakstvanu\ZssoClient\Exceptions\AuthorizationFailedException;
use Misakstvanu\ZssoClient\Exceptions\LoginRequiredException;
use Misakstvanu\ZssoClient\Socialite\ZssoProvider;
use Misakstvanu\ZssoClient\SsoUser;

beforeEach(function () {
    config([
        'zsso.server_url' => 'https://zsso.test',
        'zsso.client_id' => 'client-id',
        'zsso.client_secret' => 'client-secret',
        'zsso.scopes' => 'profile email skautis',
    ]);

    Http::preventStrayRequests();
});

/**
 * The driver, talking to the given request instead of the container's.
 */
function zssoDriver(?Request $request = null): ZssoProvider
{
    /** @var ZssoProvider $driver */
    $driver = Socialite::driver('zsso');

    return $driver->setRequest($request ?? requestWithSession('/auth/zsso/redirect'));
}

it('registers the zsso socialite driver', function () {
    fakeZssoServer();

    expect(Socialite::driver('zsso'))->toBeInstanceOf(ZssoProvider::class);
});

it('builds an authorization url with PKCE, state and the configured scopes', function () {
    fakeZssoServer();

    $request = requestWithSession('/auth/zsso/redirect');

    $url = zssoDriver($request)->redirect()->getTargetUrl();
    $query = queryOf($url);

    $verifier = $request->session()->get('code_verifier');

    expect($url)->toStartWith('https://zsso.test/oauth/authorize?')
        ->and($query['client_id'])->toBe('client-id')
        ->and($query['redirect_uri'])->toBe(url('/auth/zsso/callback'))
        ->and($query['response_type'])->toBe('code')
        ->and($query['scope'])->toBe('profile email skautis')
        ->and($query['state'])->toBe($request->session()->get('state'))
        ->and($query['state'])->not->toBeEmpty()
        ->and($query['code_challenge_method'])->toBe('S256')
        ->and($verifier)->toHaveLength(96)
        ->and($query['code_challenge'])->toBe(rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='))
        ->and($query)->not->toHaveKey('prompt');
});

it('passes prompt=none through to the authorization url', function () {
    fakeZssoServer();

    $url = zssoDriver()->with(['prompt' => 'none'])->redirect()->getTargetUrl();

    expect(queryOf($url)['prompt'])->toBe('none')
        ->and(queryOf($url))->toHaveKey('code_challenge');
});

it('takes the authorization endpoint from the discovery document', function () {
    fakeZssoServer([
        'zsso.test/.well-known/openid-configuration' => Http::response(discoveryDocument([
            'authorization_endpoint' => 'https://login.zsso.test/authorize',
        ])),
    ]);

    expect(zssoDriver()->redirect()->getTargetUrl())->toStartWith('https://login.zsso.test/authorize?');
});

it('falls back to the conventional authorization path when discovery fails', function () {
    Http::fake(['zsso.test/*' => Http::response('', 503)]);

    expect(zssoDriver()->redirect()->getTargetUrl())->toStartWith('https://zsso.test/oauth/authorize?');
});

it('exchanges the code and maps the user, skautis included', function () {
    fakeZssoServer([
        'zsso.test/oauth/token' => Http::response([
            'token_type' => 'Bearer',
            'expires_in' => 900,
            'access_token' => 'access-token',
            'refresh_token' => 'refresh-token',
            'scope' => 'profile email skautis',
        ]),
        'zsso.test/api/userinfo' => Http::response(userinfoPayload(['skautis' => skautisPayload()])),
    ]);

    $request = requestWithSession('/auth/zsso/callback?code=auth-code&state=state-value');
    $request->session()->put('state', 'state-value');
    $request->session()->put('code_verifier', 'code-verifier');

    $user = zssoDriver($request)->user();

    expect($user)->toBeInstanceOf(SsoUser::class)
        ->and($user->sub)->toBe('0192a3f4-1111-2222-3333-444455556666')
        ->and($user->email)->toBe('jana@example.cz')
        ->and($user->emailVerified)->toBeTrue()
        ->and($user->skautis?->userId)->toBe(12345)
        ->and($user->skautis?->roleId)->toBe(678)
        ->and($user->skautis?->loginId)->toBeNull()
        ->and($user->token)->toBe('access-token')
        ->and($user->refreshToken)->toBe('refresh-token')
        ->and($user->expiresIn)->toBe(900)
        ->and($user->scopes)->toBe(['profile', 'email', 'skautis']);

    Http::assertSent(function ($request) {
        return $request->url() === 'https://zsso.test/oauth/token'
            && $request['grant_type'] === 'authorization_code'
            && $request['code'] === 'auth-code'
            && $request['code_verifier'] === 'code-verifier'
            && $request['client_id'] === 'client-id'
            && $request['client_secret'] === 'client-secret'
            && $request['redirect_uri'] === url('/auth/zsso/callback');
    });

    Http::assertSent(function ($request) {
        return $request->url() === 'https://zsso.test/api/userinfo'
            && $request->hasHeader('Authorization', 'Bearer access-token');
    });
});

it('maps a user without a skautis session', function () {
    fakeZssoServer([
        'zsso.test/api/userinfo' => Http::response(userinfoPayload()),
    ]);

    $user = zssoDriver()->userFromToken('access-token');

    expect($user->skautis)->toBeNull()
        ->and($user->name)->toBe('Jana Nováková')
        ->and($user->token)->toBe('access-token');
});

it('raises LoginRequiredException when a silent login could not finish without the user', function (string $error) {
    fakeZssoServer();

    $request = requestWithSession('/auth/zsso/callback?'.http_build_query([
        'error' => $error,
        'error_description' => 'The user has to do something first.',
        'state' => 'state-value',
    ]));
    $request->session()->put('state', 'state-value');

    try {
        zssoDriver($request)->user();
    } catch (LoginRequiredException $exception) {
        expect($exception->error)->toBe($error)
            ->and($exception->getMessage())->toBe('The user has to do something first.');

        return;
    }

    test()->fail('LoginRequiredException was not raised.');
})->with(['login_required', 'consent_required', 'interaction_required']);

it('raises AuthorizationFailedException for any other callback error', function () {
    fakeZssoServer();

    $request = requestWithSession('/auth/zsso/callback?error=access_denied&state=state-value');
    $request->session()->put('state', 'state-value');

    try {
        zssoDriver($request)->user();
    } catch (AuthorizationFailedException $exception) {
        expect($exception)->not->toBeInstanceOf(LoginRequiredException::class)
            ->and($exception->error)->toBe('access_denied')
            ->and($exception->description)->toBeNull()
            ->and($exception->getMessage())->toBe('The zSSO authorization request failed with [access_denied].');

        return;
    }

    $this->fail('The driver accepted an access_denied callback.');
});

it('refreshes an access token at the token endpoint', function () {
    fakeZssoServer([
        'zsso.test/oauth/token' => Http::response([
            'access_token' => 'new-access-token',
            'refresh_token' => 'new-refresh-token',
            'expires_in' => 900,
            'scope' => 'profile email',
        ]),
    ]);

    $token = zssoDriver()->refreshToken('refresh-token');

    expect($token->token)->toBe('new-access-token')
        ->and($token->refreshToken)->toBe('new-refresh-token')
        ->and($token->expiresIn)->toBe(900)
        ->and($token->approvedScopes)->toBe(['profile', 'email']);

    Http::assertSent(fn ($request) => $request->url() === 'https://zsso.test/oauth/token'
        && $request['grant_type'] === 'refresh_token'
        && $request['refresh_token'] === 'refresh-token');
});
