<?php

namespace Misakstvanu\ZssoClient;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * This app's own access token — a Passport client-credentials token with the
 * `integration` scope of contract C-2, the bearer of every app-to-app call
 * (contract C-6) and of the apps lookup behind it.
 *
 * The token is cached until a minute before it expires; a receiver that
 * refuses it tells the caller to `forget()` it and mint a new one.
 */
class ClientCredentials
{
    /**
     * The only scope a client-credentials token may carry (contract C-2).
     */
    public const SCOPE = 'integration';

    /**
     * Mint a token this many seconds before the cached one expires.
     */
    public const EXPIRY_LEEWAY = 60;

    public function __construct(protected Discovery $discovery) {}

    /**
     * A live token, from the cache or from the token endpoint — `null` when
     * the server refused to mint one.
     */
    public function token(string $scope = self::SCOPE): ?string
    {
        $cached = Cache::get($this->cacheKey($scope));

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        return $this->mint($scope);
    }

    /**
     * Drop the cached token, so the next call mints a fresh one.
     */
    public function forget(string $scope = self::SCOPE): void
    {
        Cache::forget($this->cacheKey($scope));
    }

    protected function mint(string $scope): ?string
    {
        try {
            $response = Http::asForm()->acceptJson()->timeout(5)->post($this->discovery->url('token_endpoint'), [
                'grant_type' => 'client_credentials',
                'client_id' => (string) config('zsso.client_id'),
                'client_secret' => (string) config('zsso.client_secret'),
                'scope' => $scope,
            ]);
        } catch (Throwable) {
            return null;
        }

        if ($response->failed()) {
            return null;
        }

        $token = $response->json('access_token');

        if (! is_string($token) || $token === '') {
            return null;
        }

        $expiresIn = $response->json('expires_in');

        if (is_numeric($expiresIn) && (int) $expiresIn > self::EXPIRY_LEEWAY) {
            Cache::put($this->cacheKey($scope), $token, (int) $expiresIn - self::EXPIRY_LEEWAY);
        }

        return $token;
    }

    protected function cacheKey(string $scope): string
    {
        return 'zsso.client_token.'.sha1(implode('|', [
            $this->discovery->issuer(),
            (string) config('zsso.client_id'),
            $scope,
        ]));
    }
}
