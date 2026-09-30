<?php

declare(strict_types=1);

namespace Zakobo\Outbox\Console\Commands;

use Illuminate\Console\Command;
use Zakobo\Outbox\Actions\RelayWebhookOutboxAction;

final class RelayWebhookOutboxCommand extends Command
{
    protected $signature = 'webhooks:relay';

    protected $description = 'Relay every outbox message the fast path has not yet enqueued';

    public function handle(RelayWebhookOutboxAction $relayWebhookOutbox): int
    {
        $enqueuedOutboxMessageCount = $relayWebhookOutbox->execute();

        $this->info("Relayed {$enqueuedOutboxMessageCount} outbox message(s).");

        return self::SUCCESS;
    }
}
