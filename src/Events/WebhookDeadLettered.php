<?php

namespace Misakstvanu\ZssoClient\Events;

use Misakstvanu\ZssoClient\Webhooks\WebhookEnvelope;
use Throwable;

/**
 * A webhook was retried through the whole contract C-7 backoff and the
 * receiver never took it. Nothing will deliver it now: an app that has to
 * know listens for this event (an alert, a row in a dead-letter table).
 */
class WebhookDeadLettered
{
    /**
     * @param  string  $app  the receiving app's slug
     */
    public function __construct(
        public readonly WebhookEnvelope $envelope,
        public readonly string $app,
        public readonly ?Throwable $exception = null,
    ) {}
}
