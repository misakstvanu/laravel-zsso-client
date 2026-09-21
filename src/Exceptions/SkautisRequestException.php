<?php

namespace Misakstvanu\ZssoClient\Exceptions;

use Illuminate\Http\Client\Response;
use RuntimeException;

/**
 * A C-5 SkautIS session call was refused by zSSO: the token does not carry
 * the `skautis:session` scope, the role the app asked for is not one of the
 * user's, or the server failed outright.
 *
 * "No session" is not one of these — that is a `404` and comes back as
 * `null`. A server that could not reach SkautIS raises the
 * `SkautisUnavailableException` subclass instead, so an app can retry that
 * one and only that one.
 */
class SkautisRequestException extends RuntimeException
{
    public function __construct(
        public readonly ?Response $response = null,
        string $message = '',
    ) {
        parent::__construct(
            $message ?: 'The zSSO SkautIS session call failed.',
            $response?->status() ?? 0,
        );
    }

    /**
     * zSSO answered with an error status. The C-5 error shape is
     * `{"message": "…"}`, so `$exception->response?->json('message')` is what
     * the server said.
     */
    public static function failed(Response $response): static
    {
        return new static($response, "The zSSO SkautIS session call answered {$response->status()}.");
    }
}
