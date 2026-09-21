<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use Misakstvanu\ZssoClient\Tests\TestCase;

pest()->extend(TestCase::class)->in(__DIR__);

/*
|--------------------------------------------------------------------------
| Fixtures
|--------------------------------------------------------------------------
|
| Pest loads every test file's top-level functions into one process, so the
| payloads more than one file needs live here rather than in a test file.
|
*/

/**
 * The zSSO discovery document (contract C-1).
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function discoveryDocument(array $overrides = []): array
{
    return array_merge([
        'issuer' => 'https://zsso.test',
        'authorization_endpoint' => 'https://zsso.test/oauth/authorize',
        'token_endpoint' => 'https://zsso.test/oauth/token',
        'userinfo_endpoint' => 'https://zsso.test/api/userinfo',
        'jwks_uri' => 'https://zsso.test/.well-known/jwks.json',
        'end_session_endpoint' => 'https://zsso.test/logout',
        'apps_endpoint' => 'https://zsso.test/api/apps',
        'scopes_supported' => ['profile', 'email', 'skautis', 'skautis:session', 'integration'],
        'response_types_supported' => ['code'],
        'grant_types_supported' => ['authorization_code', 'refresh_token', 'client_credentials'],
        'code_challenge_methods_supported' => ['S256'],
    ], $overrides);
}

/**
 * A userinfo response (contract C-3).
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function userinfoPayload(array $overrides = []): array
{
    return array_merge([
        'sub' => '0192a3f4-1111-2222-3333-444455556666',
        'email' => 'jana@example.cz',
        'email_verified' => true,
        'name' => 'Jana Nováková',
        'nickname' => 'Žofka',
        'skautis_name' => 'Jana Nováková Skautová',
        'skautis_nickname' => 'Žofinka',
        'skautis_sex' => 'zena',
        'skautis_birthday' => '1997-03-01',
        'birthday' => '1998-04-02',
        'street' => 'Dlouhá 1',
        'city' => 'Brno',
        'zip' => '60200',
        'avatar_url' => null,
        'unit' => ['id' => 910, 'name' => '12. oddíl Střelka', 'registration_number' => '614.02.12', 'type' => 'oddil'],
        'stredisko' => ['id' => 900, 'name' => 'středisko Lípa Praha 4', 'registration_number' => '614.02'],
        'updated_at' => '2026-09-08T12:00:00+02:00',
    ], $overrides);
}

/**
 * The `skautis` block of a userinfo response, without the `login_id` that
 * only the `skautis:session` scope grants.
 *
 * @return array<string, mixed>
 */
function skautisPayload(): array
{
    return [
        'user_id' => 12345,
        'role_id' => 678,
        'unit_id' => 910,
        'logout_at' => '2026-09-08T14:35:00+02:00',
        'roles' => [
            ['id' => 678, 'name' => 'vedoucí oddílu', 'unit_id' => 910, 'unit_name' => '12. oddíl Střelka'],
        ],
    ];
}

/**
 * A request with a session, which the driver needs for the OAuth state and
 * the PKCE code verifier.
 */
function requestWithSession(string $uri): Request
{
    $request = Request::create($uri);
    $request->setLaravelSession(app('session.store'));

    return $request;
}

/**
 * Stub the server, with the standard discovery document unless the test
 * brings its own. Laravel keeps the stubs in the order they were registered
 * and answers with the first match, so a test may only call this once.
 *
 * @param  array<string, mixed>  $stubs
 */
function fakeZssoServer(array $stubs = []): void
{
    Http::fake($stubs + [
        'zsso.test/.well-known/openid-configuration' => Http::response(discoveryDocument()),
    ]);
}

/**
 * @return array<string, string>
 */
function queryOf(string $url): array
{
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    /** @var array<string, string> $query */
    return $query;
}

/**
 * An RSA key pair standing in for the zSSO signing key, generated once per
 * process (generating one costs about as much as a whole test). `n` and `e`
 * are the raw modulus and exponent the JWK set publishes.
 *
 * @return array{private: string, public: string, kid: string, n: string, e: string}
 */
function integrationKeyPair(): array
{
    static $pair = null;

    if ($pair !== null) {
        return $pair;
    }

    $key = openssl_pkey_new([
        'private_key_bits' => 2048,
        'private_key_type' => OPENSSL_KEYTYPE_RSA,
    ]);

    openssl_pkey_export($key, $private);
    $details = openssl_pkey_get_details($key);

    return $pair = [
        'private' => (string) $private,
        'public' => $details['key'],
        'kid' => hash('sha256', $details['key']),
        'n' => $details['rsa']['n'],
        'e' => $details['rsa']['e'],
    ];
}

/**
 * The server's JWK set, built from that key pair exactly the way zSSO builds
 * its own (`kid` is the sha256 of the public key PEM).
 *
 * @return array{keys: array<int, array<string, string>>}
 */
function integrationJwks(?string $kid = null): array
{
    $pair = integrationKeyPair();

    return ['keys' => [[
        'kty' => 'RSA',
        'alg' => 'RS256',
        'use' => 'sig',
        'kid' => $kid ?? $pair['kid'],
        'n' => rtrim(strtr(base64_encode($pair['n']), '+/', '-_'), '='),
        'e' => rtrim(strtr(base64_encode($pair['e']), '+/', '-_'), '='),
    ]]];
}

/**
 * A client-credentials access token shaped like Passport's: `aud` is the
 * caller's client id, the scopes live in a `scopes` claim and there is no
 * `kid` header unless a test asks for one.
 *
 * @param  array<int, string>  $scopes
 */
function integrationToken(
    string $clientId = 'zirafa-client-id',
    array $scopes = ['integration'],
    ?DateTimeImmutable $expiresAt = null,
    ?string $kid = null,
    ?string $privateKey = null,
): string {
    $pair = integrationKeyPair();

    $configuration = Configuration::forAsymmetricSigner(
        new Sha256,
        InMemory::plainText($privateKey ?? $pair['private']),
        InMemory::plainText($pair['public']),
    );

    $now = new DateTimeImmutable;

    $builder = $configuration->builder()
        ->permittedFor($clientId)
        ->identifiedBy(bin2hex(random_bytes(16)))
        ->issuedAt($now)
        ->canOnlyBeUsedAfter($now)
        ->expiresAt($expiresAt ?? $now->modify('+1 hour'))
        ->relatedTo($clientId)
        ->withClaim('scopes', $scopes);

    if ($kid !== null) {
        $builder = $builder->withHeader('kid', $kid);
    }

    return $builder->getToken($configuration->signer(), $configuration->signingKey())->toString();
}

/**
 * Stub the whole server an integration receiver talks to: discovery, the JWK
 * set, the token endpoint this app mints its own token at, and the app list
 * it resolves the caller through (contract C-4).
 *
 * @param  array<string, mixed>  $stubs
 * @param  array<int, array<string, mixed>>|null  $apps
 */
function fakeIntegrationServer(array $stubs = [], ?array $apps = null): void
{
    fakeZssoServer($stubs + [
        'zsso.test/.well-known/jwks.json' => Http::response(integrationJwks()),
        'zsso.test/oauth/token' => Http::response([
            'access_token' => 'this-apps-own-token',
            'token_type' => 'Bearer',
            'expires_in' => 3600,
        ]),
        'zsso.test/api/apps' => Http::response(['data' => $apps ?? [[
            'slug' => 'zirafa',
            'client_id' => 'zirafa-client-id',
            'name' => 'Žirafa',
            'base_url' => 'https://zirafa.test',
            'login_url' => 'https://zirafa.test/auth/zsso/redirect',
            'integration_url' => 'https://zirafa.test/api/integration/v1',
            'webhook_url' => 'https://zirafa.test/api/integration/v1/webhooks',
            'icon' => '🦒',
        ]]]),
    ]);
}

/**
 * A webhook envelope on the wire (contract C-7), as a receiver gets it.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function webhookPayload(array $overrides = []): array
{
    return array_merge([
        'id' => '0192a3f4-aaaa-7bbb-8ccc-ddddeeeeffff',
        'type' => 'zirafa.attendee.upserted',
        'app' => 'zirafa',
        'occurred_at' => '2026-09-08T12:00:00+02:00',
        'data' => ['attendee_id' => 42],
    ], $overrides);
}
