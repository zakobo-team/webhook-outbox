<?php

declare(strict_types=1);

namespace Zakobo\WebhookOutbox\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use PHPUnit\Framework\Attributes\Test;
use Zakobo\WebhookOutbox\Actions\RelayWebhookOutboxAction;
use Zakobo\WebhookOutbox\Models\WebhookOutboxMessage;
use Zakobo\WebhookOutbox\Tests\TestCase;

/**
 * Real `database` queue driver on the MySQL test connection, with `after_commit` enabled as an application may
 * configure it. The queue is not faked: the point is when the push happens relative to `enqueued_at`.
 */
final class RelayAfterCommitQueueTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'webhook-outbox.subscribers' => [
                'auth' => [
                    'url' => 'https://auth.example.test/webhooks',
                    'signing_secret' => 'auth-secret',
                    'events' => '*',
                ],
            ],
            'queue.default' => 'database',
            'queue.connections.database.after_commit' => true,
        ]);
        $this->useQueueTable('jobs');
    }

    #[Test]
    public function a_failed_push_on_an_after_commit_queue_leaves_the_row_unqueued_and_a_later_sweep_enqueues_it(): void
    {
        Exceptions::fake();
        $outboxMessage = WebhookOutboxMessage::factory()->create(['subscriber' => 'auth', 'event' => 'thing.happened']);
        $this->useQueueTable('missing_jobs_table');

        $enqueuedCount = app(RelayWebhookOutboxAction::class)->execute([$outboxMessage->id]);

        $this->assertSame(0, $enqueuedCount);
        $this->assertNull($outboxMessage->fresh()->enqueued_at);
        $this->assertSame(0, DB::table('jobs')->count());

        $this->useQueueTable('jobs');

        $secondSweepEnqueuedCount = app(RelayWebhookOutboxAction::class)->execute([$outboxMessage->id]);

        $this->assertSame(1, $secondSweepEnqueuedCount);
        $this->assertSame(1, DB::table('jobs')->count());
        $this->assertNotNull($outboxMessage->fresh()->enqueued_at);
    }

    #[Test]
    public function a_working_after_commit_queue_receives_the_job_and_the_row_is_marked_enqueued(): void
    {
        $outboxMessage = WebhookOutboxMessage::factory()->create(['subscriber' => 'auth', 'event' => 'thing.happened']);

        $enqueuedCount = app(RelayWebhookOutboxAction::class)->execute([$outboxMessage->id]);

        $this->assertSame(1, $enqueuedCount);
        $this->assertSame(1, DB::table('jobs')->count());
        $this->assertNotNull($outboxMessage->fresh()->enqueued_at);
    }

    private function useQueueTable(string $table): void
    {
        config(['queue.connections.database.table' => $table]);
        $this->app->forgetInstance('queue');
        $this->app->forgetInstance('queue.connection');
    }
}
