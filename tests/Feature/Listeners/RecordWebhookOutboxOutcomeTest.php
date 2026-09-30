<?php

declare(strict_types=1);

namespace Zakobo\Outbox\Tests\Feature\Listeners;

use GuzzleHttp\Psr7\Response;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use PHPUnit\Framework\Attributes\Test;
use Spatie\WebhookServer\Events\FinalWebhookCallFailedEvent;
use Spatie\WebhookServer\Events\WebhookCallFailedEvent;
use Spatie\WebhookServer\Events\WebhookCallSucceededEvent;
use Zakobo\Outbox\Enums\WebhookOutboxStatus;
use Zakobo\Outbox\Listeners\RecordWebhookOutboxOutcome;
use Zakobo\Outbox\Models\WebhookOutboxMessage;
use Zakobo\Outbox\Tests\TestCase;

final class RecordWebhookOutboxOutcomeTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function listener_is_registered_for_every_outcome_event(): void
    {
        Event::fake();

        Event::assertListening(
            WebhookCallSucceededEvent::class,
            [RecordWebhookOutboxOutcome::class, 'handleWebhookCallSucceededEvent'],
        );
        Event::assertListening(
            WebhookCallFailedEvent::class,
            [RecordWebhookOutboxOutcome::class, 'handleWebhookCallFailedEvent'],
        );
        Event::assertListening(
            FinalWebhookCallFailedEvent::class,
            [RecordWebhookOutboxOutcome::class, 'handleFinalWebhookCallFailedEvent'],
        );
    }

    #[Test]
    public function a_database_failure_while_recording_the_outcome_is_reported_and_never_thrown_into_the_caller(): void
    {
        Exceptions::fake();
        $outboxMessage = WebhookOutboxMessage::factory()->create();

        app(RecordWebhookOutboxOutcome::class)->handleWebhookCallSucceededEvent(new WebhookCallSucceededEvent(
            'post',
            'https://auth.example.test/webhooks',
            [],
            [],
            ['outbox_message_id' => $outboxMessage->id],
            [],
            999999999,
            new Response(204),
            null,
            null,
            'webhook-success',
            null,
        ));

        Exceptions::assertReported(QueryException::class);
        $this->assertSame(WebhookOutboxStatus::Pending, $outboxMessage->fresh()->status);
    }

    #[Test]
    public function a_successful_call_records_success(): void
    {
        $outboxMessage = WebhookOutboxMessage::factory()->create();

        app(RecordWebhookOutboxOutcome::class)->handleWebhookCallSucceededEvent(new WebhookCallSucceededEvent(
            'post',
            'https://auth.example.test/webhooks',
            [],
            [],
            ['outbox_message_id' => $outboxMessage->id],
            [],
            1,
            new Response(204),
            null,
            null,
            'webhook-success',
            null,
        ));

        $outboxMessage->refresh();
        $this->assertSame(WebhookOutboxStatus::Succeeded, $outboxMessage->status);
        $this->assertSame(1, $outboxMessage->attempts);
        $this->assertSame(204, $outboxMessage->last_status_code);
        $this->assertNotNull($outboxMessage->delivered_at);
    }

    #[Test]
    public function a_failed_attempt_stays_pending_and_records_the_attempt(): void
    {
        $outboxMessage = WebhookOutboxMessage::factory()->create();

        app(RecordWebhookOutboxOutcome::class)->handleWebhookCallFailedEvent(new WebhookCallFailedEvent(
            'post',
            'https://auth.example.test/webhooks',
            [],
            [],
            ['outbox_message_id' => $outboxMessage->id],
            [],
            2,
            new Response(503),
            'server_error',
            'Service unavailable',
            'webhook-failed',
            null,
        ));

        $outboxMessage->refresh();
        $this->assertSame(WebhookOutboxStatus::Pending, $outboxMessage->status);
        $this->assertSame(2, $outboxMessage->attempts);
        $this->assertSame(503, $outboxMessage->last_status_code);
        $this->assertSame('Service unavailable', $outboxMessage->last_error);
    }

    #[Test]
    public function the_final_failure_marks_the_message_as_failed(): void
    {
        $outboxMessage = WebhookOutboxMessage::factory()->create();

        app(RecordWebhookOutboxOutcome::class)->handleFinalWebhookCallFailedEvent(new FinalWebhookCallFailedEvent(
            'post',
            'https://auth.example.test/webhooks',
            [],
            [],
            ['outbox_message_id' => $outboxMessage->id],
            [],
            3,
            null,
            'timeout',
            'Request timed out',
            'webhook-final-failed',
            null,
        ));

        $outboxMessage->refresh();
        $this->assertSame(WebhookOutboxStatus::Failed, $outboxMessage->status);
        $this->assertSame(3, $outboxMessage->attempts);
        $this->assertNull($outboxMessage->last_status_code);
        $this->assertSame('Request timed out', $outboxMessage->last_error);
    }

    #[Test]
    public function a_failure_arriving_after_success_does_not_overwrite_the_success(): void
    {
        $outboxMessage = WebhookOutboxMessage::factory()->create();
        $listener = app(RecordWebhookOutboxOutcome::class);

        $listener->handleWebhookCallSucceededEvent(new WebhookCallSucceededEvent(
            'post',
            'https://auth.example.test/webhooks',
            [],
            [],
            ['outbox_message_id' => $outboxMessage->id],
            [],
            1,
            new Response(200),
            null,
            null,
            'webhook-success',
            null,
        ));

        $listener->handleFinalWebhookCallFailedEvent(new FinalWebhookCallFailedEvent(
            'post',
            'https://auth.example.test/webhooks',
            [],
            [],
            ['outbox_message_id' => $outboxMessage->id],
            [],
            2,
            new Response(503),
            'server_error',
            'Late retry failed after success.',
            'webhook-late-failure',
            null,
        ));

        $outboxMessage->refresh();
        $this->assertSame(WebhookOutboxStatus::Succeeded, $outboxMessage->status);
        $this->assertSame(1, $outboxMessage->attempts);
        $this->assertSame(200, $outboxMessage->last_status_code);
        $this->assertNull($outboxMessage->last_error);
    }

    #[Test]
    public function an_event_without_an_outbox_message_id_is_ignored(): void
    {
        $outboxMessage = WebhookOutboxMessage::factory()->create();

        app(RecordWebhookOutboxOutcome::class)->handleWebhookCallSucceededEvent(new WebhookCallSucceededEvent(
            'post',
            'https://auth.example.test/webhooks',
            [],
            [],
            [],
            [],
            1,
            new Response(204),
            null,
            null,
            'webhook-success',
            null,
        ));

        $outboxMessage->refresh();
        $this->assertSame(WebhookOutboxStatus::Pending, $outboxMessage->status);
    }

    #[Test]
    public function a_long_error_message_is_truncated_to_255_characters(): void
    {
        $outboxMessage = WebhookOutboxMessage::factory()->create();

        app(RecordWebhookOutboxOutcome::class)->handleWebhookCallFailedEvent(new WebhookCallFailedEvent(
            'post',
            'https://auth.example.test/webhooks',
            [],
            [],
            ['outbox_message_id' => $outboxMessage->id],
            [],
            1,
            null,
            'server_error',
            str_repeat('x', 500),
            'webhook-failed',
            null,
        ));

        $outboxMessage->refresh();
        $this->assertSame(255, strlen((string) $outboxMessage->last_error));
    }
}
