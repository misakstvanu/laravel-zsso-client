<?php

namespace Misakstvanu\ZssoClient;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Misakstvanu\ZssoClient\Exceptions\IntegrationRequestException;
use Misakstvanu\ZssoClient\Http\Middleware\VerifyIntegrationToken;

/**
 * One app's integration API (contract C-6), reached through
 * `Zsso::integration('<slug>')`.
 *
 * It is Laravel's HTTP client with the three things every app-to-app call
 * needs already on it — the receiver's `integration_url` as the base URL, this
 * app's client-credentials bearer and `Accept: application/json` — plus the
 * two rules that make the bearer invisible to the caller: a receiver that
 * answers 401 gets the call again with a freshly minted token, and any other
 * error status becomes an `IntegrationRequestException`.
 *
 * Every other method of `Illuminate\Http\Client\PendingRequest` is available
 * and keeps the chain fluent (`->withHeaders(…)->timeout(3)->get(…)`).
 *
 * @method Response get(string $url = '', array|string|null $query = null)
 * @method Response post(string $url = '', array $data = [])
 * @method Response put(string $url = '', array $data = [])
 * @method Response patch(string $url = '', array $data = [])
 * @method Response delete(string $url = '', array $data = [])
 * @method Response head(string $url = '', array|string|null $query = null)
 * @method Response send(string $method, string $url, array $options = [])
 * @method $this withHeaders(array $headers)
 * @method $this withHeader(string $name, mixed $value)
 * @method $this withQueryParameters(array $parameters)
 * @method $this withUrlParameters(array $parameters)
 * @method $this withOptions(array $options)
 * @method $this timeout(int $seconds)
 * @method $this connectTimeout(int $seconds)
 * @method $this asForm()
 * @method $this asMultipart()
 * @method $this attach(string $name, mixed $contents, ?string $filename = null, array $headers = [])
 */
class IntegrationClient
{
    /**
     * The methods that send the request, and therefore run the 401 retry and
     * the error handling instead of being forwarded as they are.
     *
     * @var array<int, string>
     */
    protected const SENDING_METHODS = ['get', 'post', 'put', 'patch', 'delete', 'head', 'send'];

    protected ?PendingRequest $request = null;

    /**
     * @param  string  $app  the receiving app's slug
     * @param  string  $baseUrl  its `integration_url` (contract C-4)
     */
    public function __construct(
        protected string $app,
        protected string $baseUrl,
        protected ClientCredentials $credentials,
    ) {}

    /**
     * Make the call on behalf of a zSSO user, named by their `sub`. The
     * receiver filters what it returns by that user's rights (contract C-6).
     */
    public function actingAs(string $sub): static
    {
        $this->pendingRequest()->withHeader(VerifyIntegrationToken::ACTING_USER_HEADER, $sub);

        return $this;
    }

    /**
     * The underlying HTTP client, built on first use so a client that is only
     * configured never mints a token.
     */
    public function pendingRequest(): PendingRequest
    {
        return $this->request ??= Http::baseUrl($this->baseUrl)
            ->acceptJson()
            ->withToken($this->token());
    }

    /**
     * Forward everything else to the HTTP client, keeping the chain on this
     * object so `actingAs()` and the error handling survive it.
     */
    public function __call(string $method, array $parameters): mixed
    {
        if (in_array(strtolower($method), self::SENDING_METHODS, true)) {
            return $this->dispatch($method, $parameters);
        }

        $result = $this->pendingRequest()->{$method}(...$parameters);

        return $result instanceof PendingRequest ? $this : $result;
    }

    /**
     * @param  array<int, mixed>  $parameters
     *
     * @throws IntegrationRequestException when the receiver answered with an
     *                                     error status, the retry included
     */
    protected function dispatch(string $method, array $parameters): Response
    {
        $response = $this->pendingRequest()->{$method}(...$parameters);

        if ($response->status() === 401) {
            $response = $this->retryWithFreshToken($method, $parameters) ?? $response;
        }

        if ($response->failed()) {
            throw IntegrationRequestException::failed($this->app, $response);
        }

        return $response;
    }

    /**
     * The receiver refused this app's token — it may have been revoked, or
     * signed with a key zSSO has since rotated. Drop the cached one, mint a
     * fresh one and send the call once more; `null` when zSSO would not mint
     * one, which leaves the caller with the receiver's own answer.
     *
     * @param  array<int, mixed>  $parameters
     */
    protected function retryWithFreshToken(string $method, array $parameters): ?Response
    {
        $this->credentials->forget();

        $token = $this->credentials->token();

        if ($token === null) {
            return null;
        }

        return $this->pendingRequest()->withToken($token)->{$method}(...$parameters);
    }

    /**
     * This app's client-credentials token (contract C-2, scope `integration`),
     * from the cache unless it is about to expire.
     */
    protected function token(): string
    {
        $token = $this->credentials->token();

        if ($token === null) {
            throw IntegrationRequestException::withoutToken($this->app);
        }

        return $token;
    }
}
