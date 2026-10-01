<?php

declare(strict_types=1);

namespace Zakobo\WebhookOutbox\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Zakobo\WebhookOutbox\Actions\RelayWebhookOutboxAction;
use Zakobo\WebhookOutbox\Models\WebhookOutboxMessage;

final class RelayWebhookOutboxCommand extends Command
{
    protected $signature = 'webhooks:relay';

    protected $description = 'Relay every outbox message the fast path has not yet enqueued, and fail stuck ones';

    public function handle(RelayWebhookOutboxAction $relayWebhookOutbox): int
    {
        $enqueuedOutboxMessageCount = $relayWebhookOutbox->execute();

        $this->info("Relayed {$enqueuedOutboxMessageCount} outbox message(s).");

        $stuckOutboxMessageIds = WebhookOutboxMessage::failStuck();

        if ($stuckOutboxMessageIds !== []) {
            Log::critical('Webhook outbox messages stuck in pending were marked failed.', [
                'outbox_message_ids' => $stuckOutboxMessageIds,
            ]);
            $this->warn('Marked '.count($stuckOutboxMessageIds).' stuck outbox message(s) failed.');
        }

        return self::SUCCESS;
    }
}
