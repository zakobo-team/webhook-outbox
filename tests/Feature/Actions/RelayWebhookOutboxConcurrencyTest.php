<?php

declare(strict_types=1);

namespace Zakobo\WebhookOutbox\Tests\Feature\Actions;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Spatie\WebhookServer\CallWebhookJob;
use Zakobo\WebhookOutbox\Actions\RelayWebhookOutboxAction;
use Zakobo\WebhookOutbox\Models\WebhookOutboxMessage;
use Zakobo\WebhookOutbox\Support\SubscriberRegistry;
use Zakobo\WebhookOutbox\Tests\Concerns\RunsConcurrentProcesses;
use Zakobo\WebhookOutbox\Tests\TestCase;

/**
 * Two relay sweeps can legitimately overlap: the fast path right after a commit and the scheduled
 * `webhooks:relay` sweep both call RelayWebhookOutboxAction with the same un-enqueued rows in view. The
 * `lock('for update skip locked')` re-check inside the per-row transaction must let only one of them enqueue
 * each row.
 */
#[Group('concurrency')]
final class RelayWebhookOutboxConcurrencyTest extends TestCase
{
    use RefreshDatabase;
    use RunsConcurrentProcesses;

    #[Test]
    public function two_concurrent_relay_sweeps_over_the_same_rows_enqueue_each_row_exactly_once(): void
    {
        config(['webhook-outbox.subscribers' => self::subscribers()]);
        $firstOutboxMessage = WebhookOutboxMessage::factory()->create([
            'subscriber' => 'auth',
            'event' => 'thing.happened',
        ]);
        $secondOutboxMessage = WebhookOutboxMessage::factory()->create([
            'subscriber' => 'auth',
            'event' => 'thing.happened',
        ]);
        $outboxMessageIds = [$firstOutboxMessage->id, $secondOutboxMessage->id];

        $this->withCommittedFixtures(
            test: function () use ($outboxMessageIds): void {
                $relayTask = static function () use ($outboxMessageIds): int {
                    config(['webhook-outbox.subscribers' => self::subscribers()]);
                    app()->forgetInstance(SubscriberRegistry::class);
                    Bus::fake([CallWebhookJob::class]);

                    app(RelayWebhookOutboxAction::class)->execute($outboxMessageIds);

                    return Bus::dispatched(CallWebhookJob::class)->count();
                };
                $dispatchedCountsPerProcess = $this->runConcurrently(array_fill(0, 2, $relayTask));

                $this->assertSame(2, array_sum($dispatchedCountsPerProcess));
                $enqueuedCount = WebhookOutboxMessage::query()
                    ->whereIn('id', $outboxMessageIds)
                    ->whereNotNull('enqueued_at')
                    ->count();
                $this->assertSame(2, $enqueuedCount);
            },
            cleanup: fn () => WebhookOutboxMessage::query()->whereIn('id', $outboxMessageIds)->delete(),
        );
    }

    /**
     * @return array<string, array{url: string, signing_secret: string, events: string}>
     */
    private static function subscribers(): array
    {
        return ['auth' => [
            'url' => 'https://auth.example.test/webhooks',
            'signing_secret' => 'auth-secret',
            'events' => '*',
        ]];
    }
}
