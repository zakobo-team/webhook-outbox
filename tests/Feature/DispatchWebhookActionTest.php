<?php

declare(strict_types=1);

namespace Zakobo\Outbox\Tests\Feature;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Spatie\WebhookServer\CallWebhookJob;
use Zakobo\Outbox\Actions\DispatchWebhookAction;
use Zakobo\Outbox\Enums\WebhookOutboxStatus;
use Zakobo\Outbox\Models\WebhookOutboxMessage;
use Zakobo\Outbox\Tests\TestCase;
use Zakobo\Outbox\ValueObjects\WebhookEvent;

final class DispatchWebhookActionTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_writes_one_outbox_row_and_queues_one_job_per_subscribed_subscriber(): void
    {
        Bus::fake();
        $this->useSubscribers([
            'auth' => [
                'url' => 'https://auth.example.test/webhooks',
                'signing_secret' => 'auth-secret',
                'events' => '*',
            ],
            'accounting' => [
                'url' => 'https://accounting.example.test/webhooks',
                'signing_secret' => 'accounting-secret',
                'events' => '*',
            ],
        ]);
        $webhookEvent = WebhookEvent::occurNow('thing.happened', ['id' => 'widget-1']);

        DB::transaction(fn () => app(DispatchWebhookAction::class)->execute($webhookEvent));

        Bus::assertDispatchedTimes(CallWebhookJob::class, 2);
        $this->assertEqualsCanonicalizing(
            ['auth', 'accounting'],
            $this->dispatchedWebhookJobs()->pluck('meta.subscriber')->all(),
        );
        $this->assertSame(2, WebhookOutboxMessage::query()->where('event_id', $webhookEvent->eventId)->count());
        WebhookOutboxMessage::query()
            ->where('event_id', $webhookEvent->eventId)
            ->get()
            ->each(function (WebhookOutboxMessage $outboxMessage) use ($webhookEvent): void {
                $this->assertSame($webhookEvent->eventId, $outboxMessage->event_id);
                $this->assertEquals($webhookEvent->body(), $outboxMessage->payload);
                $this->assertNotNull($outboxMessage->enqueued_at);
                $this->assertNotSame(WebhookOutboxStatus::Failed, $outboxMessage->status);
            });
    }

    #[Test]
    public function an_unsubscribed_subscriber_receives_nothing(): void
    {
        Bus::fake();
        $this->useSubscribers([
            'auth' => [
                'url' => 'https://auth.example.test/webhooks',
                'signing_secret' => 'auth-secret',
                'events' => ['thing.happened'],
            ],
            'accounting' => [
                'url' => 'https://accounting.example.test/webhooks',
                'signing_secret' => 'accounting-secret',
                'events' => ['thing.never-happens'],
            ],
        ]);
        $webhookEvent = WebhookEvent::occurNow('thing.happened', ['id' => 'widget-1']);

        DB::transaction(fn () => app(DispatchWebhookAction::class)->execute($webhookEvent));

        Bus::assertDispatchedTimes(CallWebhookJob::class, 1);
        $this->assertSame('auth', $this->dispatchedWebhookJobs()->sole()->meta['subscriber']);
        $this->assertSame(
            'auth',
            WebhookOutboxMessage::query()->where('event_id', $webhookEvent->eventId)->sole()->subscriber,
        );
    }

    #[Test]
    public function an_insert_failure_for_one_subscriber_fails_the_whole_dispatch_and_queues_nothing(): void
    {
        Bus::fake();
        $this->useSubscribers([
            'auth' => [
                'url' => 'https://auth.example.test/webhooks',
                'signing_secret' => 'auth-secret',
                'events' => '*',
            ],
            'accounting' => [
                'url' => 'https://accounting.example.test/webhooks',
                'signing_secret' => 'accounting-secret',
                'events' => '*',
            ],
        ]);
        $webhookEvent = WebhookEvent::occurNow('thing.happened', ['id' => 'widget-1']);
        WebhookOutboxMessage::factory()->create([
            'event_id' => $webhookEvent->eventId,
            'subscriber' => 'accounting',
        ]);

        $this->expectException(UniqueConstraintViolationException::class);

        try {
            DB::transaction(fn () => app(DispatchWebhookAction::class)->execute($webhookEvent));
        } finally {
            Bus::assertNotDispatched(CallWebhookJob::class);
            $this->assertSame(
                1,
                WebhookOutboxMessage::query()->where('event_id', $webhookEvent->eventId)->count(),
            );
            $this->assertSame(
                0,
                WebhookOutboxMessage::query()
                    ->where('event_id', $webhookEvent->eventId)
                    ->where('subscriber', 'auth')
                    ->count(),
            );
        }
    }

    /**
     * The relay's own per-row transaction already catches and reports a dispatch failure (proven in
     * RelayWebhookOutboxActionTest), so it never reaches DispatchWebhookAction's afterCommit callback. To hit
     * that callback's own try/report guard, the relay's sweep query itself must fail: removing the guard makes
     * this test fail with an uncaught exception instead of a reported one.
     */
    #[Test]
    public function a_relay_sweep_failure_is_reported_and_does_not_propagate_after_the_caller_commits(): void
    {
        Bus::fake();
        Exceptions::fake();
        $this->useSubscribers([
            'auth' => [
                'url' => 'https://auth.example.test/webhooks',
                'signing_secret' => 'auth-secret',
                'events' => '*',
            ],
        ]);
        $webhookEvent = WebhookEvent::occurNow('thing.happened', ['id' => 'widget-1']);
        DB::listen(function (object $query): void {
            if (
                str_contains($query->sql, 'from `webhook_outbox`')
                && str_contains($query->sql, '`enqueued_at` is null')
                && ! str_contains($query->sql, 'for update')
            ) {
                throw new RuntimeException('Simulated relay sweep failure.');
            }
        });

        DB::transaction(fn () => app(DispatchWebhookAction::class)->execute($webhookEvent));

        Exceptions::assertReported(
            fn (RuntimeException $exception): bool => $exception->getMessage() === 'Simulated relay sweep failure.',
        );
        Bus::assertNotDispatched(CallWebhookJob::class);
        $this->assertSame(1, WebhookOutboxMessage::query()->where('event_id', $webhookEvent->eventId)->count());
        $this->assertNull(
            WebhookOutboxMessage::query()->where('event_id', $webhookEvent->eventId)->sole()->enqueued_at,
        );
    }

    #[Test]
    public function nothing_is_dispatched_and_no_row_survives_when_the_callers_transaction_rolls_back(): void
    {
        Bus::fake();
        $this->useSubscribers([
            'auth' => [
                'url' => 'https://auth.example.test/webhooks',
                'signing_secret' => 'auth-secret',
                'events' => '*',
            ],
        ]);
        $webhookEvent = WebhookEvent::occurNow('thing.happened', ['id' => 'widget-1']);

        try {
            DB::transaction(function () use ($webhookEvent): void {
                app(DispatchWebhookAction::class)->execute($webhookEvent);

                throw new RuntimeException('rollback');
            });
        } catch (RuntimeException $exception) {
            $this->assertSame('rollback', $exception->getMessage());
        }

        Bus::assertNotDispatched(CallWebhookJob::class);
        $this->assertSame(0, WebhookOutboxMessage::query()->where('event_id', $webhookEvent->eventId)->count());

        DB::transaction(fn () => app(DispatchWebhookAction::class)->execute($webhookEvent));

        Bus::assertDispatchedTimes(CallWebhookJob::class, 1);
        $this->assertSame(1, WebhookOutboxMessage::query()->where('event_id', $webhookEvent->eventId)->count());
    }

    #[Test]
    public function nothing_is_dispatched_until_the_callers_transaction_commits(): void
    {
        Bus::fake();
        $this->useSubscribers([
            'auth' => [
                'url' => 'https://auth.example.test/webhooks',
                'signing_secret' => 'auth-secret',
                'events' => '*',
            ],
        ]);
        $webhookEvent = WebhookEvent::occurNow('thing.happened', ['id' => 'widget-1']);

        DB::transaction(function () use ($webhookEvent): void {
            app(DispatchWebhookAction::class)->execute($webhookEvent);

            Bus::assertNotDispatched(CallWebhookJob::class);
            $this->assertNull(
                WebhookOutboxMessage::query()->where('event_id', $webhookEvent->eventId)->sole()->enqueued_at,
            );
        });

        Bus::assertDispatchedTimes(CallWebhookJob::class, 1);
        $this->assertNotNull(
            WebhookOutboxMessage::query()->where('event_id', $webhookEvent->eventId)->sole()->enqueued_at,
        );
    }

    #[Test]
    public function a_failed_dispatch_inside_a_caught_savepoint_rolls_back_only_its_own_rows(): void
    {
        Bus::fake();
        $this->useSubscribers([
            'auth' => [
                'url' => 'https://auth.example.test/webhooks',
                'signing_secret' => 'auth-secret',
                'events' => '*',
            ],
            'accounting' => [
                'url' => 'https://accounting.example.test/webhooks',
                'signing_secret' => 'accounting-secret',
                'events' => '*',
            ],
        ]);
        $webhookEvent = WebhookEvent::occurNow('thing.happened', ['id' => 'widget-1']);
        WebhookOutboxMessage::factory()->create([
            'event_id' => $webhookEvent->eventId,
            'subscriber' => 'accounting',
        ]);

        DB::transaction(function () use ($webhookEvent): void {
            try {
                app(DispatchWebhookAction::class)->execute($webhookEvent);
            } catch (UniqueConstraintViolationException) {
                $this->assertSame(
                    0,
                    WebhookOutboxMessage::query()
                        ->where('event_id', $webhookEvent->eventId)
                        ->where('subscriber', 'auth')
                        ->count(),
                );
            }

            WebhookOutboxMessage::factory()->create(['subscriber' => 'auth', 'event_id' => 'callers-own-row']);
        });

        Bus::assertNotDispatched(CallWebhookJob::class);
        $this->assertSame(
            1,
            WebhookOutboxMessage::query()->where('event_id', $webhookEvent->eventId)->count(),
        );
        $this->assertSame(1, WebhookOutboxMessage::query()->where('event_id', 'callers-own-row')->count());
    }

    #[Test]
    public function no_subscribers_means_nothing_is_written_or_dispatched(): void
    {
        Bus::fake();
        $this->useSubscribers([]);
        $webhookEvent = WebhookEvent::occurNow('thing.happened', ['id' => 'widget-1']);

        app(DispatchWebhookAction::class)->execute($webhookEvent);

        Bus::assertNotDispatched(CallWebhookJob::class);
        $this->assertSame(0, WebhookOutboxMessage::query()->where('event_id', $webhookEvent->eventId)->count());
    }

    /**
     * @param  array<string, array{url: string, signing_secret: string, events: '*'|list<string>}>  $subscribers
     */
    private function useSubscribers(array $subscribers): void
    {
        config(['outbox.subscribers' => $subscribers]);
    }

    /**
     * @return Collection<int, CallWebhookJob>
     */
    private function dispatchedWebhookJobs(): Collection
    {
        return Bus::dispatched(CallWebhookJob::class)->values();
    }
}
