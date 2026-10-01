<?php

declare(strict_types=1);

namespace Zakobo\WebhookOutbox\Tests\Feature\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Spatie\WebhookServer\CallWebhookJob;
use Spatie\WebhookServer\Events\DispatchingWebhookCallEvent;
use Zakobo\WebhookOutbox\Enums\WebhookOutboxStatus;
use Zakobo\WebhookOutbox\Models\WebhookOutboxMessage;
use Zakobo\WebhookOutbox\Tests\TestCase;

final class RelayWebhookOutboxCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['webhook-outbox.subscribers' => [
            'auth' => [
                'url' => 'https://auth.example.test/webhooks',
                'signing_secret' => 'auth-secret',
                'events' => '*',
            ],
        ]]);
    }

    #[Test]
    public function it_relays_every_unqueued_outbox_message_and_ignores_already_enqueued_ones(): void
    {
        Bus::fake();
        $unqueued = WebhookOutboxMessage::factory()->create(['subscriber' => 'auth', 'event' => 'thing.happened']);
        $enqueued = WebhookOutboxMessage::factory()->enqueued()->create([
            'subscriber' => 'auth',
            'event' => 'thing.happened',
        ]);

        $this->artisan('webhooks:relay')
            ->expectsOutputToContain('Relayed 1 outbox message(s).')
            ->assertExitCode(Command::SUCCESS);

        Bus::assertDispatched(fn (CallWebhookJob $job): bool => $job->meta['outbox_message_id'] === $unqueued->id);
        $this->assertNotNull($unqueued->fresh()->enqueued_at);
        $this->assertNotNull($enqueued->fresh()->enqueued_at);
    }

    #[Test]
    public function it_reports_zero_when_nothing_is_unqueued(): void
    {
        Bus::fake();

        $this->artisan('webhooks:relay')
            ->expectsOutputToContain('Relayed 0 outbox message(s).')
            ->assertExitCode(Command::SUCCESS);

        Bus::assertNotDispatched(CallWebhookJob::class);
    }

    #[Test]
    public function it_fails_and_logs_enqueued_rows_left_pending_without_a_write_past_the_stuck_threshold(): void
    {
        Bus::fake();
        $logSpy = Log::spy();
        $stale = now()->subMinutes(121);
        $stuck = WebhookOutboxMessage::factory()->enqueued()->create(['updated_at' => $stale]);
        $stillRetrying = WebhookOutboxMessage::factory()->enqueued()->create(['updated_at' => $stale]);
        WebhookOutboxMessage::recordFailedAttemptById($stillRetrying->id, 2, 503, 'Service unavailable');
        $neverEnqueued = WebhookOutboxMessage::factory()->create(['updated_at' => $stale]);
        $succeeded = WebhookOutboxMessage::factory()->succeeded()->create(['updated_at' => $stale]);

        $this->artisan('webhooks:relay')
            ->expectsOutputToContain('Marked 1 stuck outbox message(s) failed.')
            ->assertExitCode(Command::SUCCESS);

        $this->assertSame(WebhookOutboxStatus::Failed, $stuck->fresh()->status);
        $this->assertSame('Delivery outcome was never recorded.', $stuck->fresh()->last_error);
        $this->assertSame(WebhookOutboxStatus::Pending, $stillRetrying->fresh()->status);
        $this->assertSame(WebhookOutboxStatus::Succeeded, $succeeded->fresh()->status);
        $this->assertSame(WebhookOutboxStatus::Pending, $neverEnqueued->fresh()->status);
        $logSpy->shouldHaveReceived('critical')
            ->once()
            ->withArgs(fn (string $message, array $context): bool => $context['outbox_message_ids'] === [$stuck->id]);
    }

    #[Test]
    public function a_dispatch_failure_makes_the_reported_count_fewer_than_the_pending_rows(): void
    {
        Bus::fake();
        Exceptions::fake();
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

        $this->artisan('webhooks:relay')
            ->expectsOutputToContain('Relayed 1 outbox message(s).')
            ->assertExitCode(Command::SUCCESS);

        Bus::assertDispatched(fn (CallWebhookJob $job): bool => $job->meta['outbox_message_id'] === $healthy->id);
        Bus::assertNotDispatched(fn (CallWebhookJob $job): bool => $job->meta['outbox_message_id'] === $failing->id);
        $this->assertNull($failing->fresh()->enqueued_at);
        $this->assertNotNull($healthy->fresh()->enqueued_at);
    }
}
