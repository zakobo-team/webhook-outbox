<?php

declare(strict_types=1);

namespace Zakobo\Outbox\Actions;

use Throwable;
use Zakobo\Outbox\Enums\WebhookOutboxStatus;
use Zakobo\Outbox\Models\WebhookOutboxMessage;
use Zakobo\Outbox\Support\SubscriberRegistry;
use Zakobo\Outbox\ValueObjects\Subscriber;
use Zakobo\Outbox\ValueObjects\WebhookEvent;

/**
 * Delivery is at-least-once (a crash between the outbox commit and the queue dispatch is retried by
 * `webhooks:relay`); receivers must dedupe on `event_id`.
 */
final readonly class DispatchWebhookAction
{
    public function __construct(
        private SubscriberRegistry $subscriberRegistry,
        private RelayWebhookOutboxAction $relayWebhookOutbox,
    ) {}

    public function execute(WebhookEvent $webhookEvent): void
    {
        $subscribers = $this->subscriberRegistry->subscribersFor($webhookEvent->name);

        if ($subscribers === []) {
            return;
        }

        $outboxConnection = WebhookOutboxMessage::outboxConnection();

        $createdOutboxMessageIds = $outboxConnection->transaction(
            fn (): array => collect($subscribers)
                ->map(fn (Subscriber $subscriber): int => $this->createOutboxMessage($webhookEvent, $subscriber)->id)
                ->values()
                ->all(),
        );

        $outboxConnection->afterCommit(function () use ($createdOutboxMessageIds): void {
            try {
                $this->relayWebhookOutbox->execute($createdOutboxMessageIds);
            } catch (Throwable $exception) {
                report($exception);
            }
        });
    }

    private function createOutboxMessage(WebhookEvent $webhookEvent, Subscriber $subscriber): WebhookOutboxMessage
    {
        return WebhookOutboxMessage::query()->create([
            'event_id' => $webhookEvent->eventId,
            'event' => $webhookEvent->name,
            'subscriber' => $subscriber->name,
            'url' => $subscriber->url,
            'payload' => $webhookEvent->body(),
            'log_context' => $webhookEvent->logContext,
            'status' => WebhookOutboxStatus::Pending,
        ]);
    }
}
