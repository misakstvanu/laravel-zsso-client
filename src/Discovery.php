<?php

namespace Misakstvanu\ZssoClient;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Throwable;

/**
 * The zSSO server's discovery document (contract C-1).
 *
 * The document is fetched once per instance and cached for `zsso.cache_ttl`
 * seconds. A server that cannot be reached is never cached: every endpoint
 * then falls back to its conventional path under `zsso.server_url`, so a
 * discovery outage does not take the login with it.
 */
class Discovery
{
    /**
     * Where the document itself lives, relative to the server.
     */
    public const PATH = '/.well-known/openid-configuration';

    /**
     * The conventional path of every endpoint the document names.
     *
     * @var array<string, string>
     */
    public const PATHS = [
        'authorization_endpoint' => '/oauth/authorize',
        'token_endpoint' => '/oauth/token',
        'userinfo_endpoint' => '/api/userinfo',
        'jwks_uri' => '/.well-known/jwks.json',
        'end_session_endpoint' => '/logout',
        'apps_endpoint' => '/api/apps',
        'skautis_session_endpoint' => '/api/skautis/session',
        'skautis_refresh_endpoint' => '/api/skautis/refresh',
        'skautis_role_endpoint' => '/api/skautis/role',
        'skautis_logout_endpoint' => '/api/skautis/logout',
    ];

    /**
     * @var array<string, mixed>|null
     */
    protected ?array $document = null;

    /**
     * The document, from memory, the cache or the server — `[]` when the
     * server did not answer with one.
     *
     * @return array<string, mixed>
     */
    public function document(): array
    {
        if ($this->document !== null) {
            return $this->document;
        }

        $cached = Cache::get($this->cacheKey());

        if (is_array($cached)) {
            return $this->document = $cached;
        }

        $fetched = $this->fetch();

        if ($fetched !== []) {
            Cache::put($this->cacheKey(), $fetched, $this->ttl());
        }

        return $this->document = $fetched;
    }

    /**
     * The absolute URL of one of the endpoints in `PATHS`.
     */
    public function url(string $key): string
    {
        if (! isset(self::PATHS[$key])) {
            throw new InvalidArgumentException("The zSSO discovery document has no endpoint [{$key}].");
        }

        $endpoint = $this->document()[$key] ?? null;

        return is_string($endpoint) && $endpoint !== ''
            ? $endpoint
            : $this->issuer().self::PATHS[$key];
    }

    /**
     * The configured server URL, without a trailing slash.
     */
    public function issuer(): string
    {
        return rtrim((string) config('zsso.server_url'), '/');
    }

    /**
     * Drop the memoised and the cached document.
     */
    public function forget(): void
    {
        $this->document = null;

        Cache::forget($this->cacheKey());
    }

    /**
     * @return array<string, mixed>
     */
    protected function fetch(): array
    {
        try {
            $response = Http::acceptJson()->timeout(5)->get($this->issuer().self::PATH);
        } catch (Throwable) {
            return [];
        }

        if ($response->failed()) {
            return [];
        }

        $document = $response->json();

        return is_array($document) ? $document : [];
    }

    protected function cacheKey(): string
    {
        return 'zsso.discovery.'.sha1($this->issuer());
    }

    protected function ttl(): int
    {
        return (int) config('zsso.cache_ttl', 3600);
    }
}
