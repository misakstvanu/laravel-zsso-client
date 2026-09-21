<?php

namespace Misakstvanu\ZssoClient\Exceptions;

use Illuminate\Http\Client\Response;

/**
 * SkautIS itself did not answer zSSO (`503`). The stored login is untouched,
 * so the call is worth making again later; nothing about it says the user
 * has to sign in anywhere.
 */
class SkautisUnavailableException extends SkautisRequestException
{
    public static function failed(Response $response): static
    {
        return new static($response, 'SkautIS did not answer zSSO; the session was left as it was.');
    }
}
