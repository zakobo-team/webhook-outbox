<?php

declare(strict_types=1);

namespace Zakobo\Outbox\Tests\Feature\Actions;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Spatie\WebhookServer\CallWebhookJob;
use Spatie\WebhookServer\Events\DispatchingWebhookCallEvent;
use Zakobo\Outbox\Actions\DispatchWebhookAction;
use Zakobo\Outbox\Actions\ReplayWebhookOutboxMessageAction;
use Zakobo\Outbox\Enums\WebhookOutboxStatus;
use Zakobo\Outbox\Exceptions\WebhookSubscriberNotConfiguredException;
use Zakobo\Outbox\Models\WebhookOutboxMessage;
use Zakobo\Outbox\Tests\TestCase;
use Zakobo\Outbox\ValueObjects\WebhookEvent;

final class ReplayWebhookOutboxMessageActionTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_resends_the_same_event_id_and_body_to_the_subscribers_current_url(): void
    {
        Bus::fake();
        config(['outbox.subscribers' => [
            'auth' => [
                'url' => 'https://auth.example.test/webhooks-v2',
                'signing_secret' => 'auth-secret',
                'events' => '*',
            ],
        ]]);
        $outboxMessage = WebhookOutboxMessage::factory()->failed()->create([
            'event' => 'thing.happened',
            'event_id' => 'e5b8d6b0-6a3e-4f0e-9f3f-7a5f3a9a1234',
            'subscriber' => 'auth',
            'url' => 'https://auth.example.test/webhooks-old',
            'payload' => [
                'event' => 'thing.happened',
                'event_id' => 'e5b8d6b0-6a3e-4f0e-9f3f-7a5f3a9a1234',
                'id' => 'widget-1',
            ],
        ]);

        app(ReplayWebhookOutboxMessageAction::class)->execute($outboxMessage);

        Bus::assertDispatchedTimes(CallWebhookJob::class, 1);
        Bus::assertDispatched(function (CallWebhookJob $job) use ($outboxMessage): bool {
            return $job->webhookUrl === 'https://auth.example.test/webhooks-v2'
                && $job->payload === $outboxMessage->payload
                && $job->meta['event_id'] === 'e5b8d6b0-6a3e-4f0e-9f3f-7a5f3a9a1234';
        });
        $outboxMessage->refresh();
        $this->assertSame('https://auth.example.test/webhooks-v2', $outboxMessage->url);
        $this->assertSame(0, $outboxMessage->attempts);
        $this->assertNull($outboxMessage->last_error);
        $this->assertNotNull($outboxMessage->enqueued_at);
    }

    #[Test]
    public function it_replays_by_id_regardless_of_the_messages_current_status(): void
    {
        Bus::fake();
        config(['outbox.subscribers' => [
            'auth' => [
                'url' => 'https://auth.example.test/webhooks-v2',
                'signing_secret' => 'auth-secret',
                'events' => '*',
            ],
        ]]);
        $outboxMessage = WebhookOutboxMessage::factory()->succeeded()->create([
            'event' => 'thing.happened',
            'subscriber' => 'auth',
        ]);

        $replayed = app(ReplayWebhookOutboxMessageAction::class)->execute($outboxMessage);

        $this->assertTrue($replayed);
        Bus::assertDispatchedTimes(CallWebhookJob::class, 1);
    }

    #[Test]
    public function only_when_failed_claims_a_failed_message_and_resends_it(): void
    {
        Bus::fake();
        config(['outbox.subscribers' => [
            'auth' => [
                'url' => 'https://auth.example.test/webhooks',
                'signing_secret' => 'auth-secret',
                'events' => '*',
            ],
        ]]);
        $outboxMessage = WebhookOutboxMessage::factory()->failed()->create([
            'event' => 'thing.happened',
            'subscriber' => 'auth',
        ]);

        $replayed = app(ReplayWebhookOutboxMessageAction::class)->execute($outboxMessage, onlyWhenFailed: true);

        $this->assertTrue($replayed);
        Bus::assertDispatchedTimes(CallWebhookJob::class, 1);
    }

    #[Test]
    public function only_when_failed_skips_a_message_that_is_no_longer_failed_without_claiming_it(): void
    {
        Bus::fake();
        config(['outbox.subscribers' => [
            'auth' => [
                'url' => 'https://auth.example.test/webhooks',
                'signing_secret' => 'auth-secret',
                'events' => '*',
            ],
        ]]);
        $outboxMessage = WebhookOutboxMessage::factory()->succeeded()->create([
            'event' => 'thing.happened',
            'subscriber' => 'auth',
            'url' => 'https://auth.example.test/webhooks-old',
        ]);

        $replayed = app(ReplayWebhookOutboxMessageAction::class)->execute($outboxMessage, onlyWhenFailed: true);

        $this->assertFalse($replayed);
        Bus::assertNotDispatched(CallWebhookJob::class);
        $this->assertSame('https://auth.example.test/webhooks-old', $outboxMessage->fresh()->url);
        $this->assertSame(WebhookOutboxStatus::Succeeded, $outboxMessage->fresh()->status);
    }

    #[Test]
    public function only_when_failed_claiming_an_already_claimed_message_returns_false_without_resending(): void
    {
        Bus::fake();
        config(['outbox.subscribers' => [
            'auth' => [
                'url' => 'https://auth.example.test/webhooks',
                'signing_secret' => 'auth-secret',
                'events' => '*',
            ],
        ]]);
        $outboxMessage = WebhookOutboxMessage::factory()->failed()->create([
            'event' => 'thing.happened',
            'subscriber' => 'auth',
        ]);

        $firstReplay = app(ReplayWebhookOutboxMessageAction::class)->execute($outboxMessage, onlyWhenFailed: true);
        $secondReplay = app(ReplayWebhookOutboxMessageAction::class)->execute($outboxMessage, onlyWhenFailed: true);

        $this->assertTrue($firstReplay);
        $this->assertFalse($secondReplay);
        Bus::assertDispatchedTimes(CallWebhookJob::class, 1);
    }

    #[Test]
    public function replaying_for_an_unconfigured_subscriber_is_rejected_without_claiming(): void
    {
        Bus::fake();
        config(['outbox.subscribers' => []]);
        $outboxMessage = WebhookOutboxMessage::factory()->failed()->create([
            'event' => 'thing.happened',
            'subscriber' => 'auth',
        ]);

        $this->expectException(WebhookSubscriberNotConfiguredException::class);

        try {
            app(ReplayWebhookOutboxMessageAction::class)->execute($outboxMessage);
        } finally {
            Bus::assertNotDispatched(CallWebhookJob::class);
            $this->assertSame(WebhookOutboxStatus::Failed, $outboxMessage->fresh()->status);
        }
    }

    #[Test]
    public function a_relay_failure_during_replay_throws_and_leaves_the_message_pending_and_unqueued(): void
    {
        Bus::fake();
        Exceptions::fake();
        config(['outbox.subscribers' => [
            'auth' => [
                'url' => 'https://auth.example.test/webhooks',
                'signing_secret' => 'auth-secret',
                'events' => '*',
            ],
        ]]);
        Event::listen(DispatchingWebhookCallEvent::class, function (DispatchingWebhookCallEvent $event): void {
            throw new RuntimeException('Simulated replay relay failure.');
        });
        $outboxMessage = WebhookOutboxMessage::factory()->failed()->create([
            'event' => 'thing.happened',
            'subscriber' => 'auth',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            "Outbox message [{$outboxMessage->id}] was not enqueued; the relay will retry it.",
        );

        try {
            app(ReplayWebhookOutboxMessageAction::class)->execute($outboxMessage);
        } finally {
            Bus::assertNotDispatched(CallWebhookJob::class);
            Exceptions::assertReported(
                fn (RuntimeException $exception): bool => $exception->getMessage()
                    === 'Simulated replay relay failure.',
            );
            $outboxMessage->refresh();
            $this->assertSame(WebhookOutboxStatus::Pending, $outboxMessage->status);
            $this->assertNull($outboxMessage->enqueued_at);
        }
    }

    #[Test]
    public function a_replay_dispatches_the_exact_same_body_bytes_as_the_original_send(): void
    {
        Bus::fake();
        config(['outbox.subscribers' => [
            'auth' => [
                'url' => 'https://auth.example.test/webhooks',
                'signing_secret' => 'auth-secret',
                'events' => '*',
            ],
        ]]);
        $webhookEvent = WebhookEvent::occurNow('thing.happened', ['id' => 'widget-1', 'name' => 'Widget']);

        DB::transaction(fn () => app(DispatchWebhookAction::class)->execute($webhookEvent));

        $originalPayload = Bus::dispatched(CallWebhookJob::class)->sole()->payload;
        $outboxMessage = WebhookOutboxMessage::query()->where('event_id', $webhookEvent->eventId)->sole()->fresh();

        app(ReplayWebhookOutboxMessageAction::class)->execute($outboxMessage);

        $replayedPayload = Bus::dispatched(CallWebhookJob::class)->last()->payload;
        $this->assertSame($originalPayload, $replayedPayload);
        $this->assertSame(array_keys($originalPayload), array_keys($replayedPayload));
    }
}
