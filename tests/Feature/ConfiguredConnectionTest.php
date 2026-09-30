<?php

declare(strict_types=1);

namespace Zakobo\Outbox\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Spatie\WebhookServer\CallWebhookJob;
use Zakobo\Outbox\Actions\DispatchWebhookAction;
use Zakobo\Outbox\Actions\RelayWebhookOutboxAction;
use Zakobo\Outbox\Models\WebhookOutboxMessage;
use Zakobo\Outbox\Tests\TestCase;
use Zakobo\Outbox\ValueObjects\WebhookEvent;

/**
 * The application's default connection is switched to an unrelated SQLite database while `outbox.connection`
 * points at a second connection to the MySQL test database, like an app whose default connection changes per
 * context. Every outbox read, write, transaction and after-commit hook must follow the configured one.
 * The outbox connection is a separate PDO, so its rows commit for real and are deleted in `finally`.
 */
final class ConfiguredConnectionTest extends TestCase
{
    use RefreshDatabase;

    private const string EVENT_NAME = 'thing.happened';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.connections.unrelated' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
            'database.connections.outbox' => config('database.connections.mysql'),
            'database.default' => 'unrelated',
            'outbox.connection' => 'outbox',
            'outbox.subscribers' => [
                'auth' => [
                    'url' => 'https://auth.example.test/webhooks',
                    'signing_secret' => 'auth-secret',
                    'events' => '*',
                ],
            ],
        ]);
    }

    #[Test]
    public function the_model_and_its_transactions_use_the_configured_connection(): void
    {
        $this->assertSame('outbox', WebhookOutboxMessage::outboxConnection()->getName());
        $this->assertSame('outbox', (new WebhookOutboxMessage)->getConnectionName());
        $this->assertFalse(DB::connection('unrelated')->getSchemaBuilder()->hasTable('webhook_outbox'));
    }

    #[Test]
    public function the_outbox_rows_and_the_after_commit_relay_ride_the_transaction_on_the_configured_connection(): void
    {
        Bus::fake();
        $webhookEvent = WebhookEvent::occurNow(self::EVENT_NAME, ['id' => 'widget-1']);
        $outboxConnection = DB::connection('outbox');

        try {
            try {
                $outboxConnection->transaction(function () use ($webhookEvent): void {
                    app(DispatchWebhookAction::class)->execute($webhookEvent);

                    throw new RuntimeException('rollback');
                });
            } catch (RuntimeException) {
            }

            Bus::assertNotDispatched(CallWebhookJob::class);
            $this->assertSame(0, $this->outboxRowCount($webhookEvent));

            $outboxConnection->beginTransaction();
            app(DispatchWebhookAction::class)->execute($webhookEvent);
            Bus::assertNotDispatched(CallWebhookJob::class);
            $outboxConnection->commit();

            Bus::assertDispatchedTimes(CallWebhookJob::class, 1);
            $this->assertSame(1, $this->outboxRowCount($webhookEvent));
            $this->assertNotNull(WebhookOutboxMessage::query()->where('event_id', $webhookEvent->eventId)
                ->sole()->enqueued_at);
        } finally {
            $this->deleteOutboxRows($webhookEvent);
        }
    }

    #[Test]
    public function the_relay_sweep_reads_and_marks_rows_on_the_configured_connection(): void
    {
        Bus::fake();
        $outboxMessage = WebhookOutboxMessage::factory()->create([
            'subscriber' => 'auth',
            'event' => self::EVENT_NAME,
            'event_id' => 'configured-connection-relay',
        ]);

        try {
            $enqueuedCount = app(RelayWebhookOutboxAction::class)->execute();

            $this->assertSame(1, $enqueuedCount);
            Bus::assertDispatchedTimes(CallWebhookJob::class, 1);
            $this->assertNotNull(
                DB::connection('outbox')
                    ->table('webhook_outbox')
                    ->where('id', $outboxMessage->id)
                    ->value('enqueued_at'),
            );
        } finally {
            $outboxMessage->delete();
        }
    }

    private function outboxRowCount(WebhookEvent $webhookEvent): int
    {
        return DB::connection('outbox')->table('webhook_outbox')->where('event_id', $webhookEvent->eventId)->count();
    }

    private function deleteOutboxRows(WebhookEvent $webhookEvent): void
    {
        DB::connection('outbox')->table('webhook_outbox')->where('event_id', $webhookEvent->eventId)->delete();
    }
}
