<?php

namespace Misakstvanu\ZssoClient;

use Illuminate\Container\Container;
use Illuminate\Http\Request;

/**
 * Who is behind an integration call (contract C-6): the calling app, and the
 * user it acts for when it named one with `X-Zsso-Acting-User`.
 *
 * `VerifyIntegrationToken` puts it on the request; a controller reads it back
 * with `IntegrationContext::current()`.
 */
class IntegrationContext
{
    /**
     * The request attribute the middleware stores the context under.
     */
    public const ATTRIBUTE = 'zsso.integration';

    public function __construct(
        public readonly string $callerSlug,
        public readonly ?string $actingUserSub = null,
    ) {}

    /**
     * The context of the request being handled, or `null` outside one (a
     * queued job, the console, a route without the middleware).
     */
    public static function current(): ?self
    {
        $container = Container::getInstance();

        if (! $container->bound('request')) {
            return null;
        }

        $request = $container->make('request');

        if (! $request instanceof Request) {
            return null;
        }

        $context = $request->attributes->get(self::ATTRIBUTE);

        return $context instanceof self ? $context : null;
    }

    /**
     * Put this context on a request.
     */
    public function bindTo(Request $request): void
    {
        $request->attributes->set(self::ATTRIBUTE, $this);
    }

    /**
     * Whether the caller named a user to act for.
     */
    public function actsForUser(): bool
    {
        return $this->actingUserSub !== null;
    }
}
