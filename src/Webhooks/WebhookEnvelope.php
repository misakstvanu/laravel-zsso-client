<?php

namespace Misakstvanu\ZssoClient\Webhooks;

use Carbon\CarbonInterface;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;

/**
 * One webhook (contract C-7): what an app emits with `Zsso::webhook()` and
 * what a receiver hands to its listeners.
 *
 * ```json
 * { "id": "0192…", "type": "zirafa.attendee.upserted", "app": "zirafa",
 *   "occurred_at": "2026-09-08T12:00:00+02:00", "data": { … } }
 * ```
 *
 * `id` is what receivers de-duplicate on, so every receiver of one event gets
 * the same envelope; `app` is the sending app's slug.
 *
 * @implements Arrayable<string, mixed>
 */
class WebhookEnvelope implements Arrayable
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public readonly string $id,
        public readonly string $type,
        public readonly string $app,
        public readonly CarbonInterface $occurredAt,
        public readonly array $data = [],
    ) {}

    /**
     * A fresh envelope from this app, stamped now. The id is time-ordered so
     * a receiver's primary key stays in insertion order.
     *
     * @param  array<string, mixed>  $data
     */
    public static function make(string $type, array $data = [], ?string $app = null): self
    {
        return new self(
            id: (string) Str::orderedUuid(),
            type: $type,
            app: $app ?? (string) config('zsso.app_slug'),
            occurredAt: Date::now(),
            data: $data,
        );
    }

    /**
     * The envelope a receiver was sent, after validation.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        $data = $payload['data'] ?? [];

        return new self(
            id: (string) ($payload['id'] ?? ''),
            type: (string) ($payload['type'] ?? ''),
            app: (string) ($payload['app'] ?? ''),
            occurredAt: Date::parse((string) ($payload['occurred_at'] ?? 'now')),
            data: is_array($data) ? $data : [],
        );
    }

    /**
     * The JSON body of contract C-7.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'app' => $this->app,
            'occurred_at' => $this->occurredAt->toIso8601String(),
            'data' => $this->data,
        ];
    }

    /**
     * One value of the event's payload, dot notation included.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return data_get($this->data, $key, $default);
    }
}
