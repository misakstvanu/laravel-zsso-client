<?php

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use Misakstvanu\ZssoClient\Events\WebhookReceived;
use Misakstvanu\ZssoClient\Facades\Zsso;
use Misakstvanu\ZssoClient\Models\WebhookReceipt;
use Misakstvanu\ZssoClient\Tests\Fixtures\RecordingWebhookHandler;
use Misakstvanu\ZssoClient\Webhooks\WebhookEnvelope;

beforeEach(function () {
    config([
        'zsso.server_url' => 'https://zsso.test',
        'zsso.client_id' => 'this-app-client-id',
        'zsso.client_secret' => 'this-app-secret',
        'zsso.app_slug' => 'zskauting',
    ]);

    RecordingWebhookHandler::flush();

    fakeIntegrationServer();
});

/**
 * Deliver an envelope the way another app does: the receiver route with a
 * client-credentials bearer of the app the envelope names.
 *
 * @param  array<string, mixed>  $payload
 */
function deliverWebhook(array $payload = []): TestResponse
{
    return test()->withToken(integrationToken())->postJson('/api/integration/v1/webhooks', webhookPayload($payload));
}

test('the receiver route is registered on the configured path behind zsso.integration', function () {
    $route = collect(app('router')->getRoutes()->getRoutes())
        ->first(fn ($route) => $route->uri() === 'api/integration/v1/webhooks');

    expect($route)->not->toBeNull()
        ->and($route->methods())->toContain('POST')
        ->and($route->gatherMiddleware())->toContain('zsso.integration')
        ->and($route->getName())->toBe('zsso.webhooks');
});

test('a webhook without a valid integration token is refused', function () {
    $this->postJson('/api/integration/v1/webhooks', webhookPayload())
        ->assertUnauthorized();

    expect(WebhookReceipt::query()->count())->toBe(0);
});

test('a registered handler runs and the sender is told it was handled', function () {
    Zsso::onWebhook('zirafa.attendee.upserted', RecordingWebhookHandler::class);

    deliverWebhook()
        ->assertOk()
        ->assertJson(['message' => 'Webhook handled.']);

    expect(RecordingWebhookHandler::$handled)->toHaveCount(1)
        ->and(RecordingWebhookHandler::$handled[0])->toBeInstanceOf(WebhookEnvelope::class)
        ->and(RecordingWebhookHandler::$handled[0]->id)->toBe(webhookPayload()['id'])
        ->and(RecordingWebhookHandler::$handled[0]->type)->toBe('zirafa.attendee.upserted')
        ->and(RecordingWebhookHandler::$handled[0]->app)->toBe('zirafa')
        ->and(RecordingWebhookHandler::$handled[0]->data)->toBe(['attendee_id' => 42])
        ->and(RecordingWebhookHandler::$handled[0]->get('attendee_id'))->toBe(42);
});

test('a closure handler runs too', function () {
    $seen = null;

    Zsso::onWebhook('zirafa.attendee.upserted', function (WebhookEnvelope $envelope) use (&$seen) {
        $seen = $envelope->id;
    });

    deliverWebhook()->assertOk();

    expect($seen)->toBe(webhookPayload()['id']);
});

test('the envelope is stored and dispatched as an event', function () {
    Event::fake([WebhookReceived::class]);

    deliverWebhook();

    Event::assertDispatched(WebhookReceived::class, fn (WebhookReceived $event) => $event->envelope->id === webhookPayload()['id']);

    $receipt = WebhookReceipt::query()->sole();

    expect($receipt->id)->toBe(webhookPayload()['id'])
        ->and($receipt->type)->toBe('zirafa.attendee.upserted')
        ->and($receipt->received_at)->not->toBeNull();
});

test('the same envelope id is only processed once', function () {
    Zsso::onWebhook('zirafa.attendee.upserted', RecordingWebhookHandler::class);

    deliverWebhook()->assertOk();

    deliverWebhook()
        ->assertOk()
        ->assertJson(['message' => 'This webhook was already received.']);

    expect(RecordingWebhookHandler::$handled)->toHaveCount(1)
        ->and(WebhookReceipt::query()->count())->toBe(1);
});

test('a type nobody handles is accepted, stored and logged at debug', function () {
    Log::spy();

    deliverWebhook(['type' => 'zirafa.something.nobody.listens.to'])
        ->assertAccepted()
        ->assertJson(['message' => 'Webhook accepted.']);

    expect(WebhookReceipt::query()->count())->toBe(1);

    Log::shouldHaveReceived('debug')->once();
});

test('a handler that throws gives the envelope id back, so a redelivery is processed', function () {
    Zsso::onWebhook('zirafa.attendee.upserted', function () {
        throw new RuntimeException('the handler blew up');
    });

    // The 500 is what makes the sender retry, and the released id is what
    // lets that retry be processed instead of answered as a duplicate.
    deliverWebhook()->assertStatus(500);

    expect(WebhookReceipt::query()->count())->toBe(0);
});

test('an envelope that names another app than the token is refused', function () {
    deliverWebhook(['app' => 'kucharka'])
        ->assertForbidden()
        ->assertJson(['message' => "This envelope claims to come from [kucharka], but the token is [zirafa]'s."]);

    expect(WebhookReceipt::query()->count())->toBe(0);
});

test('a malformed envelope is refused', function () {
    test()->withToken(integrationToken())
        ->postJson('/api/integration/v1/webhooks', ['id' => 'not-a-uuid', 'app' => 'zirafa'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['id', 'type', 'occurred_at']);
});
