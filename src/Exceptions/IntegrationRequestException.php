<?php

namespace Misakstvanu\ZssoClient\Exceptions;

use Illuminate\Http\Client\Response;
use RuntimeException;

/**
 * An app-to-app call (contract C-6) did not work out: the receiver answered
 * with an error status, zSSO lists no such app, or it would not mint the
 * token the call needs.
 *
 * `$response` is the receiver's answer when there was one — an error shape of
 * contract C-6 is `{"message": "…"}`, so `$exception->response?->json('message')`
 * is what the receiver said.
 */
class IntegrationRequestException extends RuntimeException
{
    public function __construct(
        public readonly string $app,
        public readonly ?Response $response = null,
        string $message = '',
    ) {
        parent::__construct(
            $message ?: "The integration call to [{$app}] failed.",
            $response?->status() ?? 0,
        );
    }

    /**
     * The receiver answered with an error status.
     */
    public static function failed(string $app, Response $response): self
    {
        return new self($app, $response, "The integration call to [{$app}] answered {$response->status()}.");
    }

    /**
     * Neither `zsso.apps` nor the server's app list names an integration URL
     * for the app.
     */
    public static function unknownApp(string $app): self
    {
        return new self($app, message: "zSSO knows no app [{$app}] with an integration URL.");
    }

    /**
     * Neither `zsso.apps` nor the server's app list names a webhook URL for
     * the app an envelope is addressed to (contract C-7).
     */
    public static function withoutWebhookUrl(string $app): self
    {
        return new self($app, message: "zSSO knows no app [{$app}] with a webhook URL.");
    }

    /**
     * zSSO refused to mint this app's client-credentials token, so the call
     * has no bearer to make.
     */
    public static function withoutToken(string $app): self
    {
        return new self($app, message: "zSSO would not mint a client-credentials token for the call to [{$app}].");
    }
}
