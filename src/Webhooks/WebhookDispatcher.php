<?php

namespace Misakstvanu\ZssoClient\Webhooks;

use Misakstvanu\ZssoClient\AppDirectory;
use Misakstvanu\ZssoClient\Jobs\DeliverWebhook;

/**
 * One event on its way out (contract C-7), built by `Zsso::webhook()`:
 *
 * ```php
 * Zsso::webhook('zirafa.attendee.upserted', ['id' => 42])->to('zskauting');
 * Zsso::webhook('zirafa.attendee.upserted', ['id' => 42])->toAll();
 * ```
 *
 * Naming the receivers is what queues the delivery — one `DeliverWebhook` job
 * per receiver, all carrying the same envelope, so a receiver that is down
 * only holds up its own job.
 */
class WebhookDispatcher
{
    public function __construct(protected WebhookEnvelope $envelope, protected AppDirectory $apps) {}

    /**
     * The envelope the receivers will be sent.
     */
    public function envelope(): WebhookEnvelope
    {
        return $this->envelope;
    }

    /**
     * Queue the delivery to the named apps.
     *
     * @return array<int, string> the slugs a job was queued for
     */
    public function to(string ...$apps): array
    {
        $slugs = array_values(array_unique(array_filter($apps)));

        foreach ($slugs as $slug) {
            DeliverWebhook::dispatch($this->envelope, $slug);
        }

        return $slugs;
    }

    /**
     * Queue the delivery to every app that receives webhooks — this one
     * excluded, since an app does not tell itself what it just did.
     *
     * @return array<int, string> the slugs a job was queued for
     */
    public function toAll(): array
    {
        return $this->to(...$this->receivers());
    }

    /**
     * Every app with a `webhook_url`: the server's list (contract C-4) plus
     * the local `zsso.apps` overrides, which is how an app receives webhooks
     * in development before it is registered.
     *
     * @return array<int, string>
     */
    protected function receivers(): array
    {
        $slugs = [];

        foreach ($this->apps->apps() as $app) {
            if (filled($app['webhook_url'] ?? null) && filled($app['slug'] ?? null)) {
                $slugs[] = (string) $app['slug'];
            }
        }

        $overrides = config('zsso.apps');

        foreach (is_array($overrides) ? $overrides : [] as $slug => $override) {
            if (is_array($override) && filled($override['webhook_url'] ?? null)) {
                $slugs[] = (string) $slug;
            }
        }

        return array_values(array_diff(array_unique($slugs), [(string) config('zsso.app_slug')]));
    }
}
