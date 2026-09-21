<?php

namespace Misakstvanu\ZssoClient;

use Closure;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Misakstvanu\ZssoClient\Exceptions\SkautisRequestException;
use Misakstvanu\ZssoClient\Exceptions\SkautisUnavailableException;

/**
 * One user's SkautIS session on zSSO (contract C-5), reached through
 * `Zsso::skautis($user)`.
 *
 * zSSO owns the SkautIS login: it logged the user in, it holds the SOAP
 * token and it keeps it alive. An app reads that login with `session()` to
 * hydrate its own SOAP client (`SkautisSessionData::applyTo()`), and proxies
 * the three things a user may do with it — `refresh()` (keep-alive),
 * `switchRole()` and `logout()` — instead of talking to SkautIS behind zSSO's
 * back.
 *
 * Every call carries the user's own access token, which is why the app needs
 * the `skautis:session` scope (contract C-2) for it. A user with no SkautIS
 * session is not an error: every method answers `null` (`logout()` answers
 * `false`) for them.
 */
class SkautisClient
{
    /**
     * @param  Closure(): string  $token  the user's access token, resolved per
     *                                    call so a spent one is refreshed first
     */
    public function __construct(
        protected Discovery $discovery,
        protected Closure $token,
    ) {}

    /**
     * The stored SkautIS login, or `null` when the user has none.
     */
    public function session(): ?SkautisSessionData
    {
        return $this->sessionFrom(
            $this->request()->get($this->discovery->url('skautis_session_endpoint'))
        );
    }

    /**
     * SkautIS's keep-alive: the login is prolonged and the session comes back
     * with the new `logoutAt`.
     */
    public function refresh(): ?SkautisSessionData
    {
        return $this->sessionFrom(
            $this->request()->post($this->discovery->url('skautis_refresh_endpoint'))
        );
    }

    /**
     * Activate another of the user's SkautIS roles. SkautIS answers with the
     * unit that role belongs to, so the returned session carries both.
     */
    public function switchRole(int $roleId): ?SkautisSessionData
    {
        return $this->sessionFrom(
            $this->request()->post($this->discovery->url('skautis_role_endpoint'), ['role_id' => $roleId])
        );
    }

    /**
     * End the SkautIS login for good — on SkautIS and on zSSO. `false` when
     * there was no session to end; the user stays signed in to zSSO either
     * way.
     */
    public function logout(): bool
    {
        $response = $this->request()->post($this->discovery->url('skautis_logout_endpoint'));

        if ($response->status() === 404) {
            return false;
        }

        $this->assertSucceeded($response);

        return true;
    }

    /**
     * The C-5 shape of one answer: a `404` is "this user has no SkautIS
     * session", not a failure.
     */
    protected function sessionFrom(Response $response): ?SkautisSessionData
    {
        if ($response->status() === 404) {
            return null;
        }

        $this->assertSucceeded($response);

        $payload = $response->json();

        return is_array($payload) ? SkautisSessionData::fromArray($payload) : null;
    }

    /**
     * @throws SkautisUnavailableException when SkautIS did not answer zSSO
     * @throws SkautisRequestException on any other error status
     */
    protected function assertSucceeded(Response $response): void
    {
        if ($response->status() === 503) {
            throw SkautisUnavailableException::failed($response);
        }

        if ($response->failed()) {
            throw SkautisRequestException::failed($response);
        }
    }

    /**
     * A request as the user. The token is resolved for every call, so one
     * that expired between two of them is refreshed rather than refused.
     */
    protected function request(): PendingRequest
    {
        return Http::acceptJson()->timeout(5)->withToken(($this->token)());
    }
}
