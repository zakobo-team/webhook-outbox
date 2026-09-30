<?php

declare(strict_types=1);

namespace Zakobo\Outbox\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Spatie\WebhookServer\CallWebhookJob;
use Zakobo\Outbox\Actions\DispatchWebhookAction;
use Zakobo\Outbox\Tests\TestCase;
use Zakobo\Outbox\ValueObjects\WebhookEvent;

final class OutboxDeliveryTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_default_headers_carry_the_event_and_subscriber_and_the_signature_is_the_hmac_of_the_body(): void
    {
        $webhookJob = $this->dispatchThingHappened();

        $this->assertSame('thing.happened', $webhookJob->headers['X-Zakobo-Webhook-Event']);
        $this->assertSame('auth', $webhookJob->headers['X-Zakobo-Webhook-Subscriber']);
        $this->assertSame(
            hash_hmac('sha256', json_encode($webhookJob->payload, JSON_THROW_ON_ERROR), 'auth-secret'),
            $webhookJob->headers['Signature'],
        );
        $this->assertTrue($webhookJob->useTimestamp);
        $this->assertSame('https://auth.example.test/webhooks', $webhookJob->webhookUrl);
    }

    #[Test]
    public function the_body_starts_with_event_event_id_and_occurred_at_followed_by_the_payload(): void
    {
        $webhookJob = $this->dispatchThingHappened();

        $this->assertSame(['event', 'event_id', 'occurred_at', 'id', 'name'], array_keys($webhookJob->payload));
        $this->assertSame('thing.happened', $webhookJob->payload['event']);
        $this->assertSame('Widget', $webhookJob->payload['name']);
    }

    #[Test]
    public function the_header_prefix_config_renames_the_event_and_subscriber_headers(): void
    {
        config(['outbox.header_prefix' => 'X-Acme-Hook']);

        $webhookJob = $this->dispatchThingHappened();

        $this->assertSame('thing.happened', $webhookJob->headers['X-Acme-Hook-Event']);
        $this->assertSame('auth', $webhookJob->headers['X-Acme-Hook-Subscriber']);
        $this->assertArrayNotHasKey('X-Zakobo-Webhook-Event', $webhookJob->headers);
        $this->assertArrayNotHasKey('X-Zakobo-Webhook-Subscriber', $webhookJob->headers);
    }

    private function dispatchThingHappened(): CallWebhookJob
    {
        Bus::fake();
        config(['outbox.subscribers' => [
            'auth' => [
                'url' => 'https://auth.example.test/webhooks',
                'signing_secret' => 'auth-secret',
                'events' => '*',
            ],
        ]]);

        DB::transaction(fn () => app(DispatchWebhookAction::class)->execute(
            WebhookEvent::occurNow('thing.happened', ['id' => 'widget-1', 'name' => 'Widget']),
        ));

        return Bus::dispatched(CallWebhookJob::class)->sole();
    }
}
