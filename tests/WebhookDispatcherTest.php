<?php

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Misakstvanu\ZssoClient\AppDirectory;
use Misakstvanu\ZssoClient\ClientCredentials;
use Misakstvanu\ZssoClient\Events\WebhookDeadLettered;
use Misakstvanu\ZssoClient\Exceptions\IntegrationRequestException;
use Misakstvanu\ZssoClient\Facades\Zsso;
use Misakstvanu\ZssoClient\Jobs\DeliverWebhook;
use Misakstvanu\ZssoClient\Webhooks\WebhookEnvelope;

beforeEach(function () {
    config([
        'zsso.server_url' => 'https://zsso.test',
        'zsso.client_id' => 'this-app-client-id',
        'zsso.client_secret' => 'this-app-secret',
        'zsso.app_slug' => 'zirafa',
    ]);
});

/**
 * The app list a broadcast picks its receivers from: this app, one that
 * receives webhooks and one that does not.
 *
 * @return array<int, array<string, mixed>>
 */
function webhookApps(): array
{
    return [
        [
            'slug' => 'zirafa',
            'client_id' => 'zirafa-client-id',
            'integration_url' => 'https://zirafa.test/api/integration/v1',
            'webhook_url' => 'https://zirafa.test/api/integration/v1/webhooks',
        ],
        [
            'slug' => 'zskauting',
            'client_id' => 'zskauting-client-id',
            'integration_url' => 'https://zskauting.test/api/integration/v1',
            'webhook_url' => 'https://zskauting.test/api/integration/v1/webhooks',
        ],
        [
            'slug' => 'kucharka',
            'client_id' => 'kucharka-client-id',
            'integration_url' => 'https://kucharka.test/api/integration/v1',
            'webhook_url' => null,
        ],
    ];
}

test('the envelope carries the C-7 shape', function () {
    Queue::fake();

    $envelope = Zsso::webhook('zirafa.attendee.upserted', ['attendee_id' => 42])->envelope();

    expect($envelope->type)->toBe('zirafa.attendee.upserted')
        ->and($envelope->app)->toBe('zirafa')
        ->and($envelope->data)->toBe(['attendee_id' => 42])
        ->and($envelope->id)->toMatch('/^[0-9a-f-]{36}$/')
        ->and(array_keys($envelope->toArray()))->toBe(['id', 'type', 'app', 'occurred_at', 'data'])
        ->and($envelope->toArray()['occurred_at'])->toBe($envelope->occurredAt->toIso8601String());
});

test('naming a receiver queues one delivery job', function () {
    Queue::fake();

    Zsso::webhook('zirafa.attendee.upserted', ['attendee_id' => 42])->to('zskauting');

    Queue::assertPushed(DeliverWebhook::class, 1);
    Queue::assertPushed(fn (DeliverWebhook $job) => $job->app === 'zskauting'
        && $job->envelope->type === 'zirafa.attendee.upserted'
        && $job->envelope->data === ['attendee_id' => 42]);
});

test('a broadcast queues one job per receiver, this app excluded', function () {
    Queue::fake();
    fakeIntegrationServer(apps: webhookApps());

    $queued = Zsso::webhook('zirafa.attendee.upserted')->toAll();

    expect($queued)->toBe(['zskauting']);
    Queue::assertPushed(DeliverWebhook::class, 1);
    Queue::assertPushed(fn (DeliverWebhook $job) => $job->app === 'zskauting');
});

test('a broadcast also reaches a receiver only the local config knows', function () {
    Queue::fake();
    config(['zsso.apps' => ['kucharka' => ['webhook_url' => 'https://kucharka.localhost/hooks']]]);
    fakeIntegrationServer(apps: webhookApps());

    $queued = Zsso::webhook('zirafa.attendee.upserted')->toAll();

    expect($queued)->toBe(['zskauting', 'kucharka']);
    Queue::assertPushed(DeliverWebhook::class, 2);
});

test('every receiver of one event gets the same envelope id', function () {
    Queue::fake();

    Zsso::webhook('zirafa.attendee.upserted')->to('zskauting', 'kucharka');

    $ids = [];

    Queue::assertPushed(DeliverWebhook::class, function (DeliverWebhook $job) use (&$ids) {
        $ids[$job->app] = $job->envelope->id;

        return true;
    });

    expect($ids)->toHaveCount(2)
        ->and(array_unique(array_values($ids)))->toHaveCount(1);
});

test('the job posts the envelope to the receiver with a client-credentials bearer', function () {
    fakeIntegrationServer(['zskauting.test/*' => Http::response(['message' => 'Webhook handled.'])], apps: webhookApps());

    $envelope = WebhookEnvelope::make('zirafa.attendee.upserted', ['attendee_id' => 42]);

    (new DeliverWebhook($envelope, 'zskauting'))->handle(app(AppDirectory::class), app(ClientCredentials::class));

    Http::assertSent(fn ($request) => $request->url() === 'https://zskauting.test/api/integration/v1/webhooks'
        && $request->method() === 'POST'
        && $request->hasHeader('Authorization', 'Bearer this-apps-own-token')
        && $request->data() === $envelope->toArray());
});

test('a receiver that answers with an error status makes the job fail', function () {
    fakeIntegrationServer(['zskauting.test/*' => Http::response(['message' => 'Nope.'], 500)], apps: webhookApps());

    $job = new DeliverWebhook(WebhookEnvelope::make('zirafa.attendee.upserted'), 'zskauting');

    expect(fn () => $job->handle(app(AppDirectory::class), app(ClientCredentials::class)))
        ->toThrow(IntegrationRequestException::class);
});

test('a receiver zSSO knows no webhook URL for makes the job fail', function () {
    fakeIntegrationServer(apps: webhookApps());

    $job = new DeliverWebhook(WebhookEnvelope::make('zirafa.attendee.upserted'), 'kucharka');

    expect(fn () => $job->handle(app(AppDirectory::class), app(ClientCredentials::class)))
        ->toThrow(IntegrationRequestException::class, 'zSSO knows no app [kucharka] with a webhook URL.');
});

test('a refused token is dropped so the next attempt mints a fresh one', function () {
    // `Http::fake()` may only be called once, so the token endpoint answers
    // differently on the second call through a sequence.
    fakeIntegrationServer([
        'zsso.test/oauth/token' => Http::sequence()
            ->push(['access_token' => 'the-refused-token', 'expires_in' => 3600])
            ->push(['access_token' => 'a-fresh-token', 'expires_in' => 3600]),
        'zskauting.test/*' => Http::response(['message' => 'Nope.'], 401),
    ], apps: webhookApps());

    $credentials = app(ClientCredentials::class);
    $job = new DeliverWebhook(WebhookEnvelope::make('zirafa.attendee.upserted'), 'zskauting');

    expect(fn () => $job->handle(app(AppDirectory::class), $credentials))->toThrow(IntegrationRequestException::class)
        ->and($credentials->token())->toBe('a-fresh-token');
});

test('the job retries on the contract backoff', function () {
    $job = new DeliverWebhook(WebhookEnvelope::make('zirafa.attendee.upserted'), 'zskauting');

    expect($job->backoff())->toBe([1, 5, 30, 120, 600])
        ->and($job->tries)->toBe(6);
});

test('a spent job is logged and dead-lettered', function () {
    Event::fake([WebhookDeadLettered::class]);
    Log::spy();

    $envelope = WebhookEnvelope::make('zirafa.attendee.upserted', ['attendee_id' => 42]);

    (new DeliverWebhook($envelope, 'zskauting'))->failed(new RuntimeException('the receiver never answered'));

    Event::assertDispatched(WebhookDeadLettered::class, fn (WebhookDeadLettered $event) => $event->app === 'zskauting'
        && $event->envelope->id === $envelope->id
        && $event->exception?->getMessage() === 'the receiver never answered');

    Log::shouldHaveReceived('error')->once();
});
