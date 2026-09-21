<?php

namespace Misakstvanu\ZssoClient\Socialite;

use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Laravel\Socialite\Two\AbstractProvider;
use Misakstvanu\ZssoClient\Discovery;
use Misakstvanu\ZssoClient\Exceptions\AuthorizationFailedException;
use Misakstvanu\ZssoClient\SsoUser;

/**
 * The `zsso` Socialite driver: an OAuth 2.0 authorization code flow with PKCE
 * (S256) against the endpoints of contract C-1, mapping contract C-3 into an
 * `SsoUser`. Every call goes through Laravel's HTTP client, so an app (and the
 * package's own tests) can fake the server with `Http::fake()`.
 */
class ZssoProvider extends AbstractProvider
{
    /**
     * OAuth separates scopes with a space, not a comma.
     *
     * @var string
     */
    protected $scopeSeparator = ' ';

    /**
     * zSSO requires PKCE from every client, confidential ones included.
     *
     * @var bool
     */
    protected $usesPKCE = true;

    /**
     * @var int
     */
    protected $encodingType = PHP_QUERY_RFC3986;

    /**
     * @var SsoUser|null
     */
    protected $user;

    protected Discovery $discovery;

    /**
     * @param  string  $clientId
     * @param  string  $clientSecret
     * @param  string  $redirectUrl
     * @param  array<string, mixed>  $guzzle
     */
    public function __construct(
        Request $request,
        $clientId,
        $clientSecret,
        $redirectUrl,
        $guzzle = [],
        ?Discovery $discovery = null,
    ) {
        parent::__construct($request, $clientId, $clientSecret, $redirectUrl, $guzzle);

        $this->discovery = $discovery ?? new Discovery;
    }

    /**
     * The scopes as `config('zsso.scopes')` spells them: a space separated
     * string (the `ZSSO_SCOPES` env value) or an array.
     *
     * @return array<int, string>
     */
    public static function parseScopes(mixed $scopes): array
    {
        if (is_array($scopes)) {
            return array_values(array_filter($scopes, static fn ($scope) => is_string($scope) && $scope !== ''));
        }

        return preg_split('/\s+/', trim((string) $scopes), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    /**
     * {@inheritdoc}
     */
    public function user()
    {
        $this->ensureAuthorizationSucceeded();

        return parent::user();
    }

    /**
     * {@inheritdoc}
     */
    public function userFromToken($token)
    {
        return SsoUser::fromUserinfo($this->getUserByToken($token), token: $token);
    }

    /**
     * {@inheritdoc}
     */
    public function getAccessTokenResponse($code)
    {
        return $this->postToTokenEndpoint($this->getTokenFields($code));
    }

    /**
     * {@inheritdoc}
     */
    protected function getAuthUrl($state)
    {
        return $this->buildAuthUrlFromBase($this->discovery->url('authorization_endpoint'), $state);
    }

    /**
     * {@inheritdoc}
     */
    protected function getTokenUrl()
    {
        return $this->discovery->url('token_endpoint');
    }

    /**
     * The token endpoint's answer to a refresh, as it came: Socialite's own
     * `refreshToken()` maps it onto a `Token` whose fields are all required,
     * and a server that answers without `refresh_token` or `expires_in` is
     * within its rights.
     *
     * @return array<string, mixed>
     */
    public function refreshTokenResponse(string $refreshToken): array
    {
        return $this->getRefreshTokenResponse($refreshToken);
    }

    /**
     * {@inheritdoc}
     */
    protected function getRefreshTokenResponse($refreshToken)
    {
        return $this->postToTokenEndpoint([
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
        ]);
    }

    /**
     * {@inheritdoc}
     *
     * @return array<string, mixed>
     */
    protected function getUserByToken($token)
    {
        $response = Http::acceptJson()
            ->withToken($token)
            ->get($this->discovery->url('userinfo_endpoint'))
            ->throw();

        return (array) $response->json();
    }

    /**
     * {@inheritdoc}
     */
    protected function mapUserToObject(array $user)
    {
        return SsoUser::fromUserinfo($user);
    }

    /**
     * {@inheritdoc}
     */
    protected function userInstance(array $response, array $user)
    {
        $expiresIn = Arr::get($response, 'expires_in');

        return $this->user = SsoUser::fromUserinfo(
            $user,
            token: Arr::get($response, 'access_token'),
            refreshToken: Arr::get($response, 'refresh_token'),
            expiresIn: is_numeric($expiresIn) ? (int) $expiresIn : null,
            scopes: explode($this->scopeSeparator, (string) Arr::get($response, 'scope', '')),
        );
    }

    /**
     * Turn an `error=…` callback into an exception before the code is used:
     * the interaction errors (the answers to `prompt=none`) into their own.
     */
    protected function ensureAuthorizationSucceeded(): void
    {
        $error = $this->request->input('error');

        if (! is_string($error) || $error === '') {
            return;
        }

        $description = $this->request->input('error_description');

        throw AuthorizationFailedException::for($error, is_string($description) ? $description : null);
    }

    /**
     * @param  array<string, mixed>  $fields
     * @return array<string, mixed>
     */
    protected function postToTokenEndpoint(array $fields): array
    {
        $response = Http::asForm()
            ->acceptJson()
            ->post($this->getTokenUrl(), $fields)
            ->throw();

        return (array) $response->json();
    }
}
