<?php

namespace Misakstvanu\ZssoClient;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * The apps registered on the zSSO server (contract C-4), cached for
 * `zsso.cache_ttl` seconds. It is what turns the `aud` of an incoming
 * integration token — the caller's OAuth client id — into an app slug.
 */
class AppDirectory
{
    /**
     * @var array<int, array<string, mixed>>|null
     */
    protected ?array $apps = null;

    public function __construct(protected Discovery $discovery, protected ClientCredentials $credentials) {}

    /**
     * Every app the server lists.
     *
     * @return array<int, array<string, mixed>>
     */
    public function apps(bool $fresh = false): array
    {
        if ($fresh) {
            $this->forget();
        }

        if ($this->apps !== null) {
            return $this->apps;
        }

        $cached = Cache::get($this->cacheKey());

        if (! is_array($cached)) {
            $cached = $this->fetch();

            if ($cached !== []) {
                Cache::put($this->cacheKey(), $cached, $this->ttl());
            }
        }

        /** @var array<int, array<string, mixed>> $cached */
        return $this->apps = $cached;
    }

    /**
     * The slug of the app that owns an OAuth client id, or `null` when the
     * server knows no such app. A client id the cached list does not hold is
     * how a freshly registered app looks, so the list is fetched again once.
     */
    public function slugForClientId(string $clientId): ?string
    {
        $slug = $this->find('client_id', $clientId)['slug'] ?? null;

        return is_string($slug) ? $slug : null;
    }

    /**
     * One app's row of the list — `base_url`, `login_url`, `integration_url`,
     * `webhook_url` and the rest of contract C-4 — or `null` when the server
     * lists no app under that slug.
     *
     * @return array<string, mixed>|null
     */
    public function app(string $slug): ?array
    {
        return $this->find('slug', $slug);
    }

    /**
     * A URL out of an app's row — `integration_url` (contract C-6),
     * `webhook_url` (contract C-7) — with the local `zsso.apps` override
     * winning over what the server lists. An override that is a plain string
     * is that app's integration URL, the shape the config file documents; one
     * that carries a whole C-4 row can name any field of it.
     */
    public function urlFor(string $slug, string $field): ?string
    {
        $apps = config('zsso.apps');
        $override = is_array($apps) ? ($apps[$slug] ?? null) : null;

        if (is_array($override)) {
            $override = $override[$field] ?? null;
        } elseif ($field !== 'integration_url') {
            $override = null;
        }

        $url = is_string($override) && $override !== ''
            ? $override
            : ($this->app($slug)[$field] ?? null);

        return is_string($url) && $url !== '' ? rtrim($url, '/') : null;
    }

    /**
     * The first app whose field holds the value. A miss on the cached list is
     * how an app registered since it was cached looks, so it is looked up
     * once more on a freshly fetched one.
     *
     * @return array<string, mixed>|null
     */
    protected function find(string $field, string $value): ?array
    {
        if ($value === '') {
            return null;
        }

        return $this->match($this->apps(), $field, $value)
            ?? $this->match($this->apps(fresh: true), $field, $value);
    }

    /**
     * Drop the memoised and the cached list.
     */
    public function forget(): void
    {
        $this->apps = null;

        Cache::forget($this->cacheKey());
    }

    /**
     * @param  array<int, array<string, mixed>>  $apps
     * @return array<string, mixed>|null
     */
    protected function match(array $apps, string $field, string $value): ?array
    {
        foreach ($apps as $app) {
            if (($app[$field] ?? null) === $value) {
                return $app;
            }
        }

        return null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function fetch(): array
    {
        $token = $this->credentials->token();

        if ($token === null) {
            return [];
        }

        try {
            $response = Http::acceptJson()->withToken($token)->timeout(5)->get($this->discovery->url('apps_endpoint'));
        } catch (Throwable) {
            return [];
        }

        if ($response->failed()) {
            return [];
        }

        $apps = $response->json('data');

        return is_array($apps) ? array_values(array_filter($apps, 'is_array')) : [];
    }

    protected function cacheKey(): string
    {
        return 'zsso.apps.'.sha1($this->discovery->issuer());
    }

    protected function ttl(): int
    {
        return (int) config('zsso.cache_ttl', 3600);
    }
}
