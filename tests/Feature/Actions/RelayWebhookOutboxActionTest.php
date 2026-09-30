<?php

declare(strict_types=1);

namespace Zakobo\Outbox\Tests\Feature\Actions;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Spatie\WebhookServer\CallWebhookJob;
use Spatie\WebhookServer\Events\DispatchingWebhookCallEvent;
use Zakobo\Outbox\Actions\RelayWebhookOutboxAction;
use Zakobo\Outbox\Enums\WebhookOutboxStatus;
use Zakobo\Outbox\Models\WebhookOutboxMessage;
use Zakobo\Outbox\Tests\TestCase;

final class RelayWebhookOutboxActionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['outbox.subscribers' => [
            'auth' => [
                'url' => 'https://auth.example.test/webhooks',
                'signing_secret' => 'auth-secret',
                'events' => '*',
            ],
        ]]);
    }

    #[Test]
    public function it_dispatches_every_unqueued_row_it_is_given_and_marks_it_enqueued(): void
    {
        Bus::fake();
        $first = WebhookOutboxMessage::factory()->create(['subscriber' => 'auth', 'event' => 'thing.happened']);
        $second = WebhookOutboxMessage::factory()->create(['subscriber' => 'auth', 'event' => 'thing.happened']);

        $enqueuedCount = app(RelayWebhookOutboxAction::class)->execute([$first->id, $second->id]);

        $this->assertSame(2, $enqueuedCount);
        Bus::assertDispatched(fn (CallWebhookJob $job): bool => $job->meta['outbox_message_id'] === $first->id);
        Bus::assertDispatched(fn (CallWebhookJob $job): bool => $job->meta['outbox_message_id'] === $second->id);
        $this->assertNotNull($first->fresh()->enqueued_at);
        $this->assertNotNull($second->fresh()->enqueued_at);
    }

    #[Test]
    public function without_ids_it_picks_up_every_never_enqueued_row_and_returns_the_enqueued_count(): void
    {
        Bus::fake();
        $firstUnqueued = WebhookOutboxMessage::factory()->create(['subscriber' => 'auth', 'event' => 'thing.happened']);
        $secondUnqueued = WebhookOutboxMessage::factory()->create(['subscriber' => 'auth', 'event' => 'thing.happened']);
        $alreadyEnqueued = WebhookOutboxMessage::factory()->enqueued()->create([
            'subscriber' => 'auth',
            'event' => 'thing.happened',
        ]);

        $enqueuedCount = app(RelayWebhookOutboxAction::class)->execute();

        $this->assertSame(2, $enqueuedCount);
        Bus::assertDispatchedTimes(CallWebhookJob::class, 2);
        Bus::assertNotDispatched(
            fn (CallWebhookJob $job): bool => $job->meta['outbox_message_id'] === $alreadyEnqueued->id,
        );
        $this->assertNotNull($firstUnqueued->fresh()->enqueued_at);
        $this->assertNotNull($secondUnqueued->fresh()->enqueued_at);
    }

    #[Test]
    public function an_already_enqueued_row_is_skipped(): void
    {
        Bus::fake();
        $enqueued = WebhookOutboxMessage::factory()->enqueued()->create([
            'subscriber' => 'auth',
            'event' => 'thing.happened',
        ]);

        $enqueuedCount = app(RelayWebhookOutboxAction::class)->execute([$enqueued->id]);

        $this->assertSame(0, $enqueuedCount);
        Bus::assertNotDispatched(fn (CallWebhookJob $job): bool => $job->meta['outbox_message_id'] === $enqueued->id);
        $this->assertNotNull($enqueued->fresh()->enqueued_at);
    }

    #[Test]
    public function passing_ids_limits_the_relay_to_those_rows(): void
    {
        Bus::fake();
        $targeted = WebhookOutboxMessage::factory()->create(['subscriber' => 'auth', 'event' => 'thing.happened']);
        $untouched = WebhookOutboxMessage::factory()->create(['subscriber' => 'auth', 'event' => 'thing.happened']);

        $enqueuedCount = app(RelayWebhookOutboxAction::class)->execute([$targeted->id]);

        $this->assertSame(1, $enqueuedCount);
        Bus::assertDispatched(fn (CallWebhookJob $job): bool => $job->meta['outbox_message_id'] === $targeted->id);
        Bus::assertNotDispatched(fn (CallWebhookJob $job): bool => $job->meta['outbox_message_id'] === $untouched->id);
        $this->assertNotNull($targeted->fresh()->enqueued_at);
        $this->assertNull($untouched->fresh()->enqueued_at);
    }

    #[Test]
    public function a_row_whose_subscriber_is_no_longer_configured_is_marked_failed_and_not_retried(): void
    {
        Bus::fake();
        $outboxMessage = WebhookOutboxMessage::factory()->create([
            'subscriber' => 'missing-subscriber',
            'event' => 'thing.happened',
        ]);

        $firstRunEnqueuedCount = app(RelayWebhookOutboxAction::class)->execute([$outboxMessage->id]);

        $this->assertSame(0, $firstRunEnqueuedCount);
        Bus::assertNotDispatched(
            fn (CallWebhookJob $job): bool => $job->meta['outbox_message_id'] === $outboxMessage->id,
        );
        $outboxMessage->refresh();
        $this->assertSame(WebhookOutboxStatus::Failed, $outboxMessage->status);
        $this->assertSame('Webhook subscriber not configured.', $outboxMessage->last_error);
        $this->assertNotNull($outboxMessage->enqueued_at);

        $enqueuedAtAfterFirstRun = $outboxMessage->enqueued_at;
        $secondRunEnqueuedCount = app(RelayWebhookOutboxAction::class)->execute([$outboxMessage->id]);

        $this->assertSame(0, $secondRunEnqueuedCount);
        $this->assertTrue($enqueuedAtAfterFirstRun->equalTo($outboxMessage->fresh()->enqueued_at));
    }

    #[Test]
    public function a_dispatch_failure_leaves_enqueued_at_null_and_others_in_the_same_run_still_go_out(): void
    {
        Bus::fake();
        Exceptions::fake();
        $logSpy = Log::spy();
        $failing = WebhookOutboxMessage::factory()->create(['subscriber' => 'auth', 'event' => 'thing.happened']);
        $healthy = WebhookOutboxMessage::factory()->create(['subscriber' => 'auth', 'event' => 'thing.happened']);
        Event::listen(
            DispatchingWebhookCallEvent::class,
            function (DispatchingWebhookCallEvent $event) use ($failing): void {
                if ($event->meta['outbox_message_id'] === $failing->id) {
                    throw new RuntimeException('Simulated relay dispatch failure.');
                }
            },
        );

        $enqueuedCount = app(RelayWebhookOutboxAction::class)->execute([$failing->id, $healthy->id]);

        $this->assertSame(1, $enqueuedCount);
        Bus::assertDispatched(fn (CallWebhookJob $job): bool => $job->meta['outbox_message_id'] === $healthy->id);
        Bus::assertNotDispatched(fn (CallWebhookJob $job): bool => $job->meta['outbox_message_id'] === $failing->id);
        $this->assertNull($failing->fresh()->enqueued_at);
        $this->assertNotNull($healthy->fresh()->enqueued_at);
        Exceptions::assertReported(
            fn (RuntimeException $exception): bool => $exception->getMessage() === 'Simulated relay dispatch failure.',
        );
        $logSpy->shouldHaveReceived('error')
            ->once()
            ->withArgs(fn (string $message, array $context): bool => $message === 'Webhook outbox relay failed.'
                && $context['outbox_message_id'] === $failing->id
                && $context['subscriber'] === 'auth'
                && $context['exception_message'] === 'Simulated relay dispatch failure.');
    }

    #[Test]
    public function a_row_left_unqueued_after_a_dispatch_failure_is_picked_up_by_the_next_relay_run(): void
    {
        Bus::fake();
        Exceptions::fake();
        $outboxMessage = WebhookOutboxMessage::factory()->create(['subscriber' => 'auth', 'event' => 'thing.happened']);
        Event::listen(DispatchingWebhookCallEvent::class, function (DispatchingWebhookCallEvent $event): void {
            throw new RuntimeException('Simulated relay dispatch failure.');
        });

        $firstRunEnqueuedCount = app(RelayWebhookOutboxAction::class)->execute([$outboxMessage->id]);
        $this->assertSame(0, $firstRunEnqueuedCount);
        Bus::assertNotDispatched(CallWebhookJob::class);
        Event::forget(DispatchingWebhookCallEvent::class);

        $secondRunEnqueuedCount = app(RelayWebhookOutboxAction::class)->execute([$outboxMessage->id]);

        $this->assertSame(1, $secondRunEnqueuedCount);
        Bus::assertDispatchedTimes(CallWebhookJob::class, 1);
        $this->assertNotNull($outboxMessage->fresh()->enqueued_at);
    }
}
