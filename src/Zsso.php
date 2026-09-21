<?php

namespace Misakstvanu\ZssoClient;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Arr;
use Laravel\Socialite\Facades\Socialite;
use Misakstvanu\ZssoClient\Exceptions\IntegrationRequestException;
use Misakstvanu\ZssoClient\Exceptions\SessionLostException;
use Misakstvanu\ZssoClient\Models\ZssoToken;
use Misakstvanu\ZssoClient\Socialite\ZssoProvider;
use Misakstvanu\ZssoClient\Webhooks\WebhookDispatcher;
use Misakstvanu\ZssoClient\Webhooks\WebhookEnvelope;
use Misakstvanu\ZssoClient\Webhooks\WebhookRegistry;
use Throwable;

/**
 * What an app calls to act for a user against zSSO. Reached through the
 * `Zsso` facade.
 */
class Zsso
{
    public function __construct(
        protected AppDirectory $apps,
        protected ClientCredentials $credentials,
        protected WebhookRegistry $webhooks,
        protected Discovery $discovery,
    ) {}

    /**
     * A user's SkautIS session on zSSO (contract C-5): read it to hydrate a
     * SOAP client of this app's own, or proxy the keep-alive, the role switch
     * and the logout.
     *
     * ```php
     * Zsso::skautis($user)->session()?->applyTo($skautis);
     * ```
     *
     * The user's own token carries the call, so the app needs the
     * `skautis:session` scope (contract C-2).
     */
    public function skautis(Authenticatable $user): SkautisClient
    {
        return new SkautisClient($this->discovery, fn (): string => $this->tokenFor($user));
    }

    /**
     * Another app's integration API (contract C-6), ready to call: the HTTP
     * client comes back with that app's `integration_url` as its base URL and
     * this app's client-credentials token as its bearer.
     *
     * @throws IntegrationRequestException when no integration URL is known
     */
    public function integration(string $app): IntegrationClient
    {
        $baseUrl = $this->integrationUrl($app);

        if ($baseUrl === null) {
            throw IntegrationRequestException::unknownApp($app);
        }

        return new IntegrationClient($app, $baseUrl, $this->credentials);
    }

    /**
     * Where an app receives integration calls: its `integration_url` of
     * contract C-4, or the local `zsso.apps` override.
     */
    protected function integrationUrl(string $app): ?string
    {
        return $this->apps->urlFor($app, 'integration_url');
    }

    /**
     * An event on its way to the other apps (contract C-7). Naming the
     * receivers is what queues it:
     *
     * ```php
     * Zsso::webhook('zirafa.attendee.upserted', ['id' => 42])->to('zskauting');
     * ```
     *
     * @param  array<string, mixed>  $data
     */
    public function webhook(string $type, array $data = []): WebhookDispatcher
    {
        return new WebhookDispatcher(WebhookEnvelope::make($type, $data), $this->apps);
    }

    /**
     * Run a handler for every webhook of one type this app receives. The
     * handler is called with the envelope; register them from a service
     * provider's `boot()`.
     *
     * @param  class-string|callable  $handler
     */
    public function onWebhook(string $type, string|callable $handler): void
    {
        $this->webhooks->listen($type, $handler);
    }

    /**
     * A live access token for a locally signed-in user, refreshed through the
     * token endpoint when the stored one is spent.
     *
     * @throws SessionLostException when this app holds no token for the user
     *                              or zSSO refused the refresh token
     */
    public function tokenFor(Authenticatable $user): string
    {
        $token = ZssoToken::forUser($user);

        if ($token === null) {
            throw new SessionLostException('This app holds no zSSO token for the user; they have to sign in again.');
        }

        return $token->isExpired() ? $this->refresh($token) : $token->access_token;
    }

    /**
     * Spend the refresh token on a new access token and keep the row in sync.
     */
    protected function refresh(ZssoToken $token): string
    {
        if (blank($token->refresh_token)) {
            throw new SessionLostException('The zSSO access token expired and there is no refresh token; the user has to sign in again.');
        }

        try {
            /** @var ZssoProvider $driver */
            $driver = Socialite::driver('zsso');

            $fresh = $driver->refreshTokenResponse($token->refresh_token);
        } catch (Throwable $exception) {
            throw new SessionLostException('zSSO refused the refresh token; the user has to sign in again.', previous: $exception);
        }

        $access = Arr::get($fresh, 'access_token');

        if (! is_string($access) || $access === '') {
            throw new SessionLostException('zSSO answered the refresh without an access token; the user has to sign in again.');
        }

        $expiresIn = Arr::get($fresh, 'expires_in');
        $scopes = ZssoProvider::parseScopes(Arr::get($fresh, 'scope', ''));

        $token->forceFill([
            'access_token' => $access,
            'refresh_token' => Arr::get($fresh, 'refresh_token') ?: $token->refresh_token,
            'expires_at' => is_numeric($expiresIn) ? now()->addSeconds((int) $expiresIn) : null,
            'scopes' => $scopes !== [] ? $scopes : $token->scopes,
        ])->save();

        return $access;
    }
}
