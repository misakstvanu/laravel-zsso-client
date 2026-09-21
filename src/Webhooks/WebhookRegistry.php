<?php

namespace Misakstvanu\ZssoClient\Webhooks;

use Illuminate\Container\Container;

/**
 * Which handler runs for which webhook type, filled by
 * `Zsso::onWebhook('zirafa.attendee.upserted', Handler::class)`.
 *
 * It is also what tells the receiver route whether an envelope was handled
 * (`200`) or only accepted (`202`): a type nobody registered a handler for is
 * still stored and still dispatched as `WebhookReceived`, but the sender is
 * told that nothing acted on it.
 *
 * A handler is invoked with the envelope as its only argument, inline with
 * the request; a handler that may fail or take its time dispatches its own
 * queued job from there.
 */
class WebhookRegistry
{
    /**
     * @var array<string, array<int, class-string|callable>>
     */
    protected array $handlers = [];

    /**
     * @param  class-string|callable  $handler  a class with a `handle()` method,
     *                                          an invokable class or a closure
     */
    public function listen(string $type, string|callable $handler): void
    {
        $this->handlers[$type][] = $handler;
    }

    /**
     * Whether any handler was registered for the type.
     */
    public function handles(string $type): bool
    {
        return ($this->handlers[$type] ?? []) !== [];
    }

    /**
     * @return array<int, class-string|callable>
     */
    public function handlersFor(string $type): array
    {
        return $this->handlers[$type] ?? [];
    }

    /**
     * The registered types, in registration order.
     *
     * @return array<int, string>
     */
    public function types(): array
    {
        return array_keys($this->handlers);
    }

    /**
     * Run every handler registered for the envelope's type. This is what the
     * package's own `WebhookReceived` listener calls.
     */
    public function dispatch(WebhookEnvelope $envelope): void
    {
        foreach ($this->handlersFor($envelope->type) as $handler) {
            $this->call($handler, $envelope);
        }
    }

    /**
     * @param  class-string|callable  $handler
     */
    protected function call(string|callable $handler, WebhookEnvelope $envelope): void
    {
        if (is_string($handler)) {
            $instance = Container::getInstance()->make($handler);

            $handler = method_exists($instance, 'handle') ? [$instance, 'handle'] : $instance;
        }

        /** @var callable $handler */
        $handler($envelope);
    }
}
