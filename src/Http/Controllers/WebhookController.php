<?php

namespace Misakstvanu\ZssoClient\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Misakstvanu\ZssoClient\Events\WebhookReceived;
use Misakstvanu\ZssoClient\IntegrationContext;
use Misakstvanu\ZssoClient\Models\WebhookReceipt;
use Misakstvanu\ZssoClient\Webhooks\WebhookEnvelope;
use Misakstvanu\ZssoClient\Webhooks\WebhookRegistry;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Where this app receives webhooks (contract C-7): `POST zsso.webhook_path`,
 * behind `zsso.integration`, so the sender is a zSSO app with a live
 * client-credentials token before anything here runs.
 *
 * - an envelope whose id was already received answers `200` and does nothing
 * - a type an app registered a handler for is handled inline and answers `200`
 * - any other type is stored, dispatched as `WebhookReceived` and answers
 *   `202`, so the sender stops retrying and the operator sees it in the log
 */
class WebhookController
{
    public function __invoke(Request $request, WebhookRegistry $registry): JsonResponse
    {
        $payload = $request->validate([
            'id' => ['required', 'uuid'],
            'type' => ['required', 'string'],
            'app' => ['required', 'string'],
            'occurred_at' => ['required', 'date'],
            'data' => ['sometimes', 'array'],
        ]);

        $envelope = WebhookEnvelope::fromArray($payload);
        $caller = IntegrationContext::current()?->callerSlug;

        if ($caller !== null && $caller !== $envelope->app) {
            return $this->answer("This envelope claims to come from [{$envelope->app}], but the token is [{$caller}]'s.", Response::HTTP_FORBIDDEN);
        }

        if (! WebhookReceipt::record($envelope)) {
            return $this->answer('This webhook was already received.');
        }

        try {
            WebhookReceived::dispatch($envelope);
        } catch (Throwable $exception) {
            // Nothing acted on it after all, so let the sender's retry through.
            WebhookReceipt::release($envelope);

            throw $exception;
        }

        if ($registry->handles($envelope->type)) {
            return $this->answer('Webhook handled.');
        }

        Log::debug('A zSSO webhook of an unhandled type was accepted.', [
            'id' => $envelope->id,
            'type' => $envelope->type,
            'app' => $envelope->app,
        ]);

        return $this->answer('Webhook accepted.', Response::HTTP_ACCEPTED);
    }

    protected function answer(string $message, int $status = Response::HTTP_OK): JsonResponse
    {
        return new JsonResponse(['message' => $message], $status);
    }
}
