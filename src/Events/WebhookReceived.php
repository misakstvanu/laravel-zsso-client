<?php

namespace Misakstvanu\ZssoClient\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Misakstvanu\ZssoClient\Webhooks\WebhookEnvelope;

/**
 * A webhook of contract C-7 reached this app, was authenticated by
 * `zsso.integration` and was seen for the first time.
 *
 * An app that wants every webhook listens for this event; one that wants a
 * single type registers a handler with `Zsso::onWebhook($type, $handler)`,
 * which the package's own listener runs from here.
 */
class WebhookReceived
{
    use Dispatchable;

    public function __construct(public readonly WebhookEnvelope $envelope) {}
}
