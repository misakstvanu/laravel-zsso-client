<?php

namespace Misakstvanu\ZssoClient\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Misakstvanu\ZssoClient\AppDirectory;
use Misakstvanu\ZssoClient\ClientCredentials;
use Misakstvanu\ZssoClient\Events\WebhookDeadLettered;
use Misakstvanu\ZssoClient\Exceptions\IntegrationRequestException;
use Misakstvanu\ZssoClient\Webhooks\WebhookEnvelope;
use Throwable;

/**
 * Delivers one envelope to one app (contract C-7): `POST <webhook_url>` with
 * this app's client-credentials bearer, the same auth as any other app-to-app
 * call (contract C-6).
 *
 * Anything but a 2xx throws, which hands the job back to the queue with the
 * contract's backoff; once the last attempt is spent, `failed()` logs it and
 * fires `WebhookDeadLettered`.
 */
class DeliverWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Seconds between attempts (contract C-7).
     *
     * @var array<int, int>
     */
    public const BACKOFF = [1, 5, 30, 120, 600];

    /**
     * The first attempt plus one per backoff step.
     */
    public int $tries = 6;

    /**
     * @param  string  $app  the receiving app's slug
     */
    public function __construct(public WebhookEnvelope $envelope, public string $app) {}

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return self::BACKOFF;
    }

    /**
     * @throws IntegrationRequestException when the receiver is unknown, zSSO
     *                                     would not mint a token, or the
     *                                     receiver did not answer 2xx
     */
    public function handle(AppDirectory $apps, ClientCredentials $credentials): void
    {
        $url = $apps->urlFor($this->app, 'webhook_url');

        if ($url === null) {
            throw IntegrationRequestException::withoutWebhookUrl($this->app);
        }

        $token = $credentials->token();

        if ($token === null) {
            throw IntegrationRequestException::withoutToken($this->app);
        }

        $response = Http::acceptJson()->withToken($token)->timeout(10)->post($url, $this->envelope->toArray());

        if ($response->status() === 401) {
            // The receiver refused the token; the next attempt mints a fresh one.
            $credentials->forget();
        }

        if ($response->failed()) {
            throw IntegrationRequestException::failed($this->app, $response);
        }
    }

    /**
     * Every attempt is spent. Nothing else will deliver this envelope.
     */
    public function failed(?Throwable $exception): void
    {
        Log::error('zSSO webhook delivery was dead-lettered.', [
            'app' => $this->app,
            'id' => $this->envelope->id,
            'type' => $this->envelope->type,
            'reason' => $exception?->getMessage(),
        ]);

        event(new WebhookDeadLettered($this->envelope, $this->app, $exception));
    }
}
