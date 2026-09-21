<?php

namespace Misakstvanu\ZssoClient\Tests\Fixtures;

use Misakstvanu\ZssoClient\Webhooks\WebhookEnvelope;

/**
 * What an app registers with `Zsso::onWebhook()`: a class whose `handle()`
 * takes the envelope. The envelopes are kept on the class so a test can see
 * that (and how often) the handler ran.
 */
class RecordingWebhookHandler
{
    /**
     * @var array<int, WebhookEnvelope>
     */
    public static array $handled = [];

    public function handle(WebhookEnvelope $envelope): void
    {
        static::$handled[] = $envelope;
    }

    public static function flush(): void
    {
        static::$handled = [];
    }
}
