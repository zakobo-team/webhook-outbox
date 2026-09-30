<?php

declare(strict_types=1);

namespace Zakobo\WebhookOutbox\Tests\Feature\Models;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Zakobo\WebhookOutbox\Enums\WebhookOutboxStatus;
use Zakobo\WebhookOutbox\Models\WebhookOutboxMessage;
use Zakobo\WebhookOutbox\Tests\TestCase;

final class WebhookOutboxMessageTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function only_when_failed_resets_a_failed_message_and_keeps_its_delivered_at(): void
    {
        $deliveredAt = now()->subDay()->startOfSecond();
        $outboxMessage = WebhookOutboxMessage::factory()->failed()->create(['delivered_at' => $deliveredAt]);

        $claimed = $outboxMessage->claimForReplay('https://auth.example.test/webhooks-v2', true);

        $this->assertTrue($claimed);
        $outboxMessage->refresh();
        $this->assertSame(WebhookOutboxStatus::Pending, $outboxMessage->status);
        $this->assertSame('https://auth.example.test/webhooks-v2', $outboxMessage->url);
        $this->assertSame(0, $outboxMessage->attempts);
        $this->assertNull($outboxMessage->last_status_code);
        $this->assertNull($outboxMessage->last_error);
        $this->assertNull($outboxMessage->enqueued_at);
        $this->assertTrue($deliveredAt->equalTo($outboxMessage->delivered_at));
    }

    #[Test]
    public function only_when_failed_claiming_an_already_claimed_message_a_second_time_fails(): void
    {
        $outboxMessage = WebhookOutboxMessage::factory()->failed()->create();

        $firstClaim = $outboxMessage->claimForReplay('https://auth.example.test/webhooks', true);
        $secondClaim = $outboxMessage->claimForReplay('https://auth.example.test/webhooks', true);

        $this->assertTrue($firstClaim);
        $this->assertFalse($secondClaim);
    }

    #[Test]
    public function only_when_failed_claiming_a_message_that_is_not_failed_returns_false_without_changing_it(): void
    {
        $outboxMessage = WebhookOutboxMessage::factory()->succeeded()->create([
            'url' => 'https://auth.example.test/webhooks-old',
        ]);

        $claimed = $outboxMessage->claimForReplay('https://auth.example.test/webhooks-v2', true);

        $this->assertFalse($claimed);
        $this->assertSame('https://auth.example.test/webhooks-old', $outboxMessage->fresh()->url);
        $this->assertSame(WebhookOutboxStatus::Succeeded, $outboxMessage->fresh()->status);
    }

    #[Test]
    public function any_status_claims_and_resets_a_message_regardless_of_status_and_keeps_delivered_at(): void
    {
        $deliveredAt = now()->subDay()->startOfSecond();
        $outboxMessage = WebhookOutboxMessage::factory()->succeeded()->create(['delivered_at' => $deliveredAt]);

        $claimed = $outboxMessage->claimForReplay('https://auth.example.test/webhooks-v2', false);

        $this->assertTrue($claimed);
        $outboxMessage->refresh();
        $this->assertSame(WebhookOutboxStatus::Pending, $outboxMessage->status);
        $this->assertSame('https://auth.example.test/webhooks-v2', $outboxMessage->url);
        $this->assertSame(0, $outboxMessage->attempts);
        $this->assertNull($outboxMessage->enqueued_at);
        $this->assertTrue($deliveredAt->equalTo($outboxMessage->delivered_at));
    }

    #[Test]
    public function any_status_claims_a_fresh_pending_message_even_when_the_reset_changes_no_column(): void
    {
        $this->freezeTime();
        $outboxMessage = WebhookOutboxMessage::factory()->create([
            'url' => 'https://auth.example.test/webhooks',
        ]);

        $claimed = $outboxMessage->claimForReplay('https://auth.example.test/webhooks', false);

        $this->assertTrue($claimed);
    }

    #[Test]
    public function the_unqueued_scope_only_matches_rows_without_an_enqueued_at(): void
    {
        $unqueued = WebhookOutboxMessage::factory()->create(['event_id' => 'e-unqueued']);
        $enqueued = WebhookOutboxMessage::factory()->enqueued()->create(['event_id' => 'e-enqueued']);

        $unqueuedIds = WebhookOutboxMessage::query()
            ->unqueued()
            ->whereIn('event_id', ['e-unqueued', 'e-enqueued'])
            ->pluck('id')
            ->all();

        $this->assertSame([$unqueued->id], $unqueuedIds);
        $this->assertNotNull($enqueued->enqueued_at);
    }

    #[Test]
    public function mark_enqueued_by_id_sets_enqueued_at(): void
    {
        $outboxMessage = WebhookOutboxMessage::factory()->create();

        WebhookOutboxMessage::markEnqueuedById($outboxMessage->id);

        $this->assertNotNull($outboxMessage->fresh()->enqueued_at);
    }
}
