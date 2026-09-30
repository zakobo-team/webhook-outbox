<?php

declare(strict_types=1);

namespace Zakobo\Outbox\Tests\Feature\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Spatie\WebhookServer\CallWebhookJob;
use Spatie\WebhookServer\Events\DispatchingWebhookCallEvent;
use Zakobo\Outbox\Models\WebhookOutboxMessage;
use Zakobo\Outbox\Tests\TestCase;

final class RelayWebhookOutboxCommandTest extends TestCase
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
