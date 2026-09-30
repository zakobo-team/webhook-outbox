<?php

declare(strict_types=1);

namespace Zakobo\Outbox\Actions;

use RuntimeException;
use Zakobo\Outbox\Exceptions\WebhookSubscriberNotConfiguredException;
use Zakobo\Outbox\Models\WebhookOutboxMessage;
use Zakobo\Outbox\Support\SubscriberRegistry;

/**
 * A resend is safe: receivers dedupe on `event_id`. With `$onlyWhenFailed`, returns false when a concurrent
 * replay already claimed the row instead of resending it.
 */
final readonly class ReplayWebhookOutboxMessageAction
{
    public function __construct(
        private SubscriberRegistry $subscriberRegistry,
        private RelayWebhookOutboxAction $relayWebhookOutbox,
    ) {}

    /**
     * @throws WebhookSubscriberNotConfiguredException
     * @throws RuntimeException When the row was claimed but the relay did not enqueue it.
     */
    public function execute(WebhookOutboxMessage $outboxMessage, bool $onlyWhenFailed = false): bool
    {
        $subscriber = $this->subscriberRegistry->find($outboxMessage->subscriber);

        if ($subscriber === null || ! $subscriber->subscribesTo($outboxMessage->event)) {
            throw WebhookSubscriberNotConfiguredException::forOutboxMessage(
                $outboxMessage->subscriber,
                $outboxMessage->event,
            );
        }

        if (! $outboxMessage->claimForReplay($subscriber->url, $onlyWhenFailed)) {
            return false;
        }

        $this->relayWebhookOutbox->execute([$outboxMessage->id]);

        if ($outboxMessage->fresh()?->enqueued_at === null) {
            throw new RuntimeException(
                "Outbox message [{$outboxMessage->id}] was not enqueued; the relay will retry it.",
            );
        }

        return true;
    }
}
