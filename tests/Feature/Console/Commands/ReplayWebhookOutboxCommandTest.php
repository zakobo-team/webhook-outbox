<?php

declare(strict_types=1);

namespace Zakobo\Outbox\Tests\Feature\Console\Commands;

use Illuminate\Console\Command;
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
use Zakobo\Outbox\Enums\WebhookOutboxStatus;
use Zakobo\Outbox\Models\WebhookOutboxMessage;
use Zakobo\Outbox\Tests\TestCase;
use Zakobo\Outbox\ValueObjects\WebhookEvent;

final class ReplayWebhookOutboxCommandTest extends TestCase
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
    public function it_fails_when_neither_ids_nor_failed_are_given(): void
    {
        Bus::fake();

        $this->artisan('webhooks:replay')
            ->expectsOutputToContain('Provide one or more outbox message ids, or pass --failed.')
            ->assertExitCode(Command::FAILURE);

        Bus::assertNotDispatched(CallWebhookJob::class);
    }

    #[Test]
    public function it_fails_when_ids_and_failed_are_both_given(): void
    {
        Bus::fake();
        $outboxMessage = WebhookOutboxMessage::factory()->failed()->create([
            'subscriber' => 'auth',
            'event' => 'thing.happened',
        ]);

        $this->artisan('webhooks:replay', ['messages' => [$outboxMessage->id], '--failed' => true])
            ->expectsOutputToContain('Provide outbox message ids or pass --failed, not both.')
            ->assertExitCode(Command::FAILURE);

        Bus::assertNotDispatched(CallWebhookJob::class);
    }

    #[Test]
    public function it_fails_before_sending_anything_when_a_message_id_is_not_a_positive_integer(): void
    {
        Bus::fake();

        $this->artisan('webhooks:replay', ['messages' => ['abc', '-1', '0']])
            ->expectsOutputToContain('Outbox message ids must be positive integers: abc, -1, 0.')
            ->assertExitCode(Command::FAILURE);

        Bus::assertNotDispatched(CallWebhookJob::class);
    }

    #[Test]
    public function it_fails_before_sending_anything_when_an_id_is_unknown(): void
    {
        Bus::fake();
        $outboxMessage = WebhookOutboxMessage::factory()->failed()->create([
            'subscriber' => 'auth',
            'event' => 'thing.happened',
        ]);

        $this->artisan('webhooks:replay', ['messages' => [$outboxMessage->id, $outboxMessage->id + 999]])
            ->expectsOutputToContain('Unknown outbox message id(s): '.($outboxMessage->id + 999).'.')
            ->assertExitCode(Command::FAILURE);

        Bus::assertNotDispatched(CallWebhookJob::class);
    }

    #[Test]
    public function it_replays_the_given_message_ids(): void
    {
        Bus::fake();
        $first = WebhookOutboxMessage::factory()->failed()->create([
            'subscriber' => 'auth',
            'event' => 'thing.happened',
        ]);
        $second = WebhookOutboxMessage::factory()->failed()->create([
            'subscriber' => 'auth',
            'event' => 'thing.happened',
        ]);

        $this->artisan('webhooks:replay', ['messages' => [$first->id, $second->id]])
            ->expectsOutputToContain("Replayed outbox message [{$first->id}] to subscriber [auth].")
            ->expectsOutputToContain("Replayed outbox message [{$second->id}] to subscriber [auth].")
            ->assertExitCode(Command::SUCCESS);

        Bus::assertDispatchedTimes(CallWebhookJob::class, 2);
        $this->assertNotNull($first->fresh()->enqueued_at);
        $this->assertNotNull($second->fresh()->enqueued_at);
    }

    #[Test]
    public function a_fresh_pending_message_replayed_immediately_is_enqueued_and_reported_as_replayed(): void
    {
        Bus::fake();
        $this->freezeTime();
        $outboxMessage = WebhookOutboxMessage::factory()->create([
            'subscriber' => 'auth',
            'event' => 'thing.happened',
        ]);

        $this->artisan('webhooks:replay', ['messages' => [$outboxMessage->id]])
            ->expectsOutputToContain("Replayed outbox message [{$outboxMessage->id}] to subscriber [auth].")
            ->assertExitCode(Command::SUCCESS);

        Bus::assertDispatchedTimes(CallWebhookJob::class, 1);
        $this->assertNotNull($outboxMessage->fresh()->enqueued_at);
    }

    #[Test]
    public function the_failed_option_replays_only_failed_messages(): void
    {
        Bus::fake();
        $failed = WebhookOutboxMessage::factory()->failed()->create([
            'subscriber' => 'auth',
            'event' => 'thing.happened',
        ]);
        $succeeded = WebhookOutboxMessage::factory()->succeeded()->create([
            'subscriber' => 'auth',
            'event' => 'thing.happened',
        ]);
        $pending = WebhookOutboxMessage::factory()->create(['subscriber' => 'auth', 'event' => 'thing.happened']);

        $this->artisan('webhooks:replay', ['--failed' => true])
            ->expectsOutputToContain("Replayed outbox message [{$failed->id}] to subscriber [auth].")
            ->assertExitCode(Command::SUCCESS);

        Bus::assertDispatched(fn (CallWebhookJob $job): bool => $job->meta['outbox_message_id'] === $failed->id);
        Bus::assertNotDispatched(
            fn (CallWebhookJob $job): bool => in_array(
                $job->meta['outbox_message_id'],
                [$succeeded->id, $pending->id],
                true,
            ),
        );
    }

    #[Test]
    public function an_unconfigured_subscriber_is_reported_per_message_without_stopping_the_others(): void
    {
        Bus::fake();
        $unconfigured = WebhookOutboxMessage::factory()->failed()->create([
            'subscriber' => 'missing-subscriber',
            'event' => 'thing.happened',
        ]);
        $configured = WebhookOutboxMessage::factory()->failed()->create([
            'subscriber' => 'auth',
            'event' => 'thing.happened',
        ]);

        $this->artisan('webhooks:replay', ['messages' => [$unconfigured->id, $configured->id]])
            ->expectsOutputToContain(
                'Webhook subscriber [missing-subscriber] is not configured for the [thing.happened] event.',
            )
            ->expectsOutputToContain("Replayed outbox message [{$configured->id}] to subscriber [auth].")
            ->assertExitCode(Command::FAILURE);

        Bus::assertDispatchedTimes(CallWebhookJob::class, 1);
    }

    #[Test]
    public function a_message_marked_failed_after_delivery_attempts_is_replayable_via_failed(): void
    {
        Bus::fake();
        $webhookEvent = WebhookEvent::occurNow('thing.happened', ['id' => 'widget-1']);

        DB::transaction(fn () => app(DispatchWebhookAction::class)->execute($webhookEvent));

        $outboxMessage = WebhookOutboxMessage::query()->where('event_id', $webhookEvent->eventId)->sole();
        $this->assertNotNull($outboxMessage->enqueued_at);
        WebhookOutboxMessage::recordFinalFailureById($outboxMessage->id, 3, 503, 'Service unavailable');

        $this->artisan('webhooks:replay', ['--failed' => true])
            ->expectsOutputToContain("Replayed outbox message [{$outboxMessage->id}] to subscriber [auth].")
            ->assertExitCode(Command::SUCCESS);

        Bus::assertDispatched(fn (CallWebhookJob $job): bool => $job->meta['outbox_message_id'] === $outboxMessage->id);
        $this->assertSame(WebhookOutboxStatus::Pending, $outboxMessage->fresh()->status);
    }

    #[Test]
    public function a_replay_whose_relay_fails_to_enqueue_is_reported_as_an_error_and_the_row_stays_unqueued(): void
    {
        Bus::fake();
        Exceptions::fake();
        $outboxMessage = WebhookOutboxMessage::factory()->failed()->create([
            'subscriber' => 'auth',
            'event' => 'thing.happened',
        ]);
        Event::listen(DispatchingWebhookCallEvent::class, function (DispatchingWebhookCallEvent $event): void {
            throw new RuntimeException('Simulated replay relay failure.');
        });

        $this->artisan('webhooks:replay', ['messages' => [$outboxMessage->id]])
            ->expectsOutputToContain("Outbox message [{$outboxMessage->id}] was not enqueued; the relay will retry it.")
            ->assertExitCode(Command::FAILURE);

        Bus::assertNotDispatched(CallWebhookJob::class);
        $this->assertNull($outboxMessage->fresh()->enqueued_at);
    }

    #[Test]
    public function a_lost_claim_is_reported_as_a_skip_and_does_not_fail_the_command(): void
    {
        Bus::fake();
        $outboxMessage = WebhookOutboxMessage::factory()->failed()->create([
            'subscriber' => 'auth',
            'event' => 'thing.happened',
        ]);
        $hasRacedTheClaim = false;

        // Simulates a concurrent replay winning the claim between this command's --failed query and its own
        // claim update: flipping the row's status just before the claim's `where status = failed` runs makes
        // that update match zero rows, exactly like a real racing claim would.
        WebhookOutboxMessage::outboxConnection()->beforeExecuting(
            function (string $query) use ($outboxMessage, &$hasRacedTheClaim): void {
                if (
                    $hasRacedTheClaim
                    || ! str_contains($query, 'update `webhook_outbox`')
                    || ! str_contains($query, '`status` = ?')
                ) {
                    return;
                }

                $hasRacedTheClaim = true;
                WebhookOutboxMessage::query()
                    ->whereKey($outboxMessage->id)
                    ->update(['status' => WebhookOutboxStatus::Succeeded]);
            },
        );

        $this->artisan('webhooks:replay', ['--failed' => true])
            ->expectsOutputToContain(
                "Outbox message [{$outboxMessage->id}] is no longer eligible for replay; skipping.",
            )
            ->assertExitCode(Command::SUCCESS);

        Bus::assertNotDispatched(CallWebhookJob::class);
        $this->assertTrue($hasRacedTheClaim);
    }
}
