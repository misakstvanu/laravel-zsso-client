<?php

namespace Misakstvanu\ZssoClient\Exceptions;

use RuntimeException;

/**
 * zSSO sent the visitor back with `error=…` instead of an authorization code.
 */
class AuthorizationFailedException extends RuntimeException
{
    public function __construct(
        public readonly string $error,
        public readonly ?string $description = null,
    ) {
        parent::__construct($description ?: "The zSSO authorization request failed with [{$error}].");
    }

    /**
     * The OpenID Connect errors a `prompt=none` request answers when it would
     * have had to show the user something: no session, a consent screen, or
     * (on zSSO) the page asking them to link SkautIS.
     *
     * @var list<string>
     */
    public const INTERACTION_ERRORS = ['login_required', 'consent_required', 'interaction_required'];

    /**
     * The exception for an OAuth error code — the interaction errors get
     * their own so a silent login can tell "the user would have to do
     * something first" from a real failure.
     */
    public static function for(string $error, ?string $description = null): self
    {
        return in_array($error, self::INTERACTION_ERRORS, true)
            ? new LoginRequiredException($error, $description)
            : new self($error, $description);
    }
}
