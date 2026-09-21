<?php

namespace Misakstvanu\ZssoClient\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use Lcobucci\JWT\Token\Parser;
use Lcobucci\JWT\UnencryptedToken;
use Lcobucci\JWT\Validation\Constraint\SignedWith;
use Lcobucci\JWT\Validation\Validator;
use Misakstvanu\ZssoClient\AppDirectory;
use Misakstvanu\ZssoClient\IntegrationContext;
use Misakstvanu\ZssoClient\JsonWebKeySet;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Guards this app's `/api/integration/v1/*` routes (contract C-6): the bearer
 * has to be a zSSO client-credentials JWT, signed by the server's current key,
 * unexpired and carrying the `integration` scope. Nothing here calls zSSO per
 * request — the signing keys and the app list are cached.
 *
 * Aliased `zsso.integration`. The `zsso.integration:acting-user` parameter is
 * for endpoints that only make sense on behalf of a user, and answers 403 when
 * the caller named none.
 */
class VerifyIntegrationToken
{
    /**
     * The header naming the user the call is made for (contract C-6).
     */
    public const ACTING_USER_HEADER = 'X-Zsso-Acting-User';

    /**
     * The middleware parameter that makes that header mandatory.
     */
    public const REQUIRE_ACTING_USER = 'acting-user';

    /**
     * The scope a client-credentials token needs (contract C-2).
     */
    public const SCOPE = 'integration';

    public function __construct(protected JsonWebKeySet $keys, protected AppDirectory $apps) {}

    public function handle(Request $request, Closure $next, ?string $requirement = null): Response
    {
        $token = $this->parse((string) $request->bearerToken());

        if ($token === null) {
            return $this->refuse('The request carries no readable zSSO bearer token.');
        }

        if (! $this->signatureIsValid($token)) {
            return $this->refuse('The bearer token was not signed by zSSO.');
        }

        if ($token->isExpired(Date::now())) {
            return $this->refuse('The bearer token has expired.');
        }

        if (! in_array(self::SCOPE, $this->scopes($token), true)) {
            return $this->refuse('The bearer token does not carry the ['.self::SCOPE.'] scope.');
        }

        $slug = $this->apps->slugForClientId($this->audience($token));

        if ($slug === null) {
            return $this->refuse('The bearer token belongs to an app zSSO does not know.');
        }

        $actingUser = trim((string) $request->header(self::ACTING_USER_HEADER)) ?: null;

        if ($requirement === self::REQUIRE_ACTING_USER && $actingUser === null) {
            return new JsonResponse([
                'message' => 'This endpoint needs the '.self::ACTING_USER_HEADER.' header.',
            ], Response::HTTP_FORBIDDEN);
        }

        (new IntegrationContext($slug, $actingUser))->bindTo($request);

        return $next($request);
    }

    protected function parse(string $jwt): ?UnencryptedToken
    {
        if ($jwt === '') {
            return null;
        }

        try {
            $token = (new Parser(new JoseEncoder))->parse($jwt);
        } catch (Throwable) {
            return null;
        }

        return $token instanceof UnencryptedToken ? $token : null;
    }

    /**
     * Passport signs with RS256 and sends no `kid`, so a token without one is
     * checked against every key in the set.
     */
    protected function signatureIsValid(UnencryptedToken $token): bool
    {
        $kid = $token->headers()->get('kid');
        $validator = new Validator;

        foreach ($this->keys->pems(is_string($kid) ? $kid : null) as $pem) {
            if ($validator->validate($token, new SignedWith(new Sha256, InMemory::plainText($pem)))) {
                return true;
            }
        }

        return false;
    }

    /**
     * The scopes league/oauth2-server writes into the `scopes` claim; `scope`
     * is read too, for a server that follows RFC 9068 instead.
     *
     * @return array<int, string>
     */
    protected function scopes(UnencryptedToken $token): array
    {
        $scopes = $token->claims()->get('scopes', $token->claims()->get('scope', []));

        if (is_string($scopes)) {
            $scopes = preg_split('/\s+/', trim($scopes), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        }

        return is_array($scopes)
            ? array_values(array_filter($scopes, 'is_string'))
            : [];
    }

    /**
     * The caller's OAuth client id: the `aud` claim, which lcobucci hands
     * back as a list when the token names more than one audience.
     */
    protected function audience(UnencryptedToken $token): string
    {
        $audience = $token->claims()->get('aud', []);

        if (is_array($audience)) {
            $audience = $audience[0] ?? '';
        }

        return is_string($audience) ? $audience : '';
    }

    protected function refuse(string $message): JsonResponse
    {
        return new JsonResponse(['message' => $message], Response::HTTP_UNAUTHORIZED);
    }
}
