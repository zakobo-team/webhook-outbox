<?php

declare(strict_types=1);

namespace Zakobo\Outbox\Tests\Unit\ValueObjects;

use Illuminate\Support\Str;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Zakobo\Outbox\Tests\TestCase;
use Zakobo\Outbox\ValueObjects\WebhookEvent;

final class WebhookEventTest extends TestCase
{
    #[Test]
    public function occur_now_generates_a_uuid_event_id_and_the_current_time(): void
    {
        $this->freezeTime();

        $webhookEvent = WebhookEvent::occurNow('thing.created', ['id' => '1']);

        $this->assertTrue(Str::isUuid($webhookEvent->eventId));
        $this->assertTrue($webhookEvent->occurredAt->equalTo(now()));
    }

    #[Test]
    public function the_reserved_event_key_in_the_payload_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Webhook event payload must not use the reserved key(s): event.');

        WebhookEvent::occurNow('thing.created', ['event' => 'spoofed']);
    }

    #[Test]
    public function the_reserved_event_id_and_occurred_at_keys_in_the_payload_throw(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Webhook event payload must not use the reserved key(s): event_id, occurred_at.');

        WebhookEvent::occurNow('thing.created', ['event_id' => 'spoofed', 'occurred_at' => 'spoofed']);
    }

    #[Test]
    public function body_places_the_envelope_keys_before_the_payload_in_a_fixed_order(): void
    {
        $webhookEvent = WebhookEvent::occurNow('thing.created', ['id' => '1', 'name' => 'Widget']);

        $this->assertSame(['event', 'event_id', 'occurred_at', 'id', 'name'], array_keys($webhookEvent->body()));
        $this->assertSame('thing.created', $webhookEvent->body()['event']);
        $this->assertSame($webhookEvent->eventId, $webhookEvent->body()['event_id']);
        $this->assertSame($webhookEvent->occurredAt->toIso8601String(), $webhookEvent->body()['occurred_at']);
        $this->assertSame('1', $webhookEvent->body()['id']);
        $this->assertSame('Widget', $webhookEvent->body()['name']);
    }

    #[Test]
    public function body_never_includes_the_log_context(): void
    {
        $webhookEvent = WebhookEvent::occurNow('thing.created', ['id' => '1'], ['account_id' => 'acme']);

        $this->assertArrayNotHasKey('account_id', $webhookEvent->body());
    }

    #[Test]
    public function two_events_generate_different_event_ids(): void
    {
        $firstEvent = WebhookEvent::occurNow('thing.created', []);
        $secondEvent = WebhookEvent::occurNow('thing.created', []);

        $this->assertNotSame($firstEvent->eventId, $secondEvent->eventId);
    }
}
