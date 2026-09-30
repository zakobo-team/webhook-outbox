<?php

declare(strict_types=1);

namespace Zakobo\WebhookOutbox\Tests\Feature\Models;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Zakobo\WebhookOutbox\Models\WebhookOutboxMessage;
use Zakobo\WebhookOutbox\Tests\TestCase;

final class WebhookOutboxMessagePruningTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_retention_follows_the_prune_after_days_config(): void
    {
        config(['webhook-outbox.prune_after_days' => 30]);
        $old = WebhookOutboxMessage::factory()->succeeded()->create(['created_at' => now()->subDays(31)]);
        $recent = WebhookOutboxMessage::factory()->succeeded()->create(['created_at' => now()->subDays(29)]);

        (new WebhookOutboxMessage)->pruneAll();

        $this->assertModelMissing($old);
        $this->assertModelExists($recent);
    }

    #[Test]
    public function messages_older_than_ninety_days_are_pruned_and_newer_ones_are_kept(): void
    {
        $old = WebhookOutboxMessage::factory()->succeeded()->create(['created_at' => now()->subDays(91)]);
        $boundary = WebhookOutboxMessage::factory()->succeeded()->create(['created_at' => now()->subDays(90)]);
        $recent = WebhookOutboxMessage::factory()->succeeded()->create(['created_at' => now()->subDays(1)]);

        (new WebhookOutboxMessage)->pruneAll();

        $this->assertModelMissing($old);
        $this->assertModelMissing($boundary);
        $this->assertModelExists($recent);
    }
}
