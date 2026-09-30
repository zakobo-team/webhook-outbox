<?php

declare(strict_types=1);

namespace Zakobo\WebhookOutbox\Actions;

use Illuminate\Support\Facades\Log;
use Spatie\WebhookServer\WebhookCall as OutgoingWebhookCall;
use Throwable;
use Zakobo\WebhookOutbox\Models\WebhookOutboxMessage;
use Zakobo\WebhookOutbox\Support\SubscriberRegistry;
use Zakobo\WebhookOutbox\ValueObjects\Subscriber;

/**
 * Delivery is at-least-once; a row is retried by `webhooks:relay` until it is marked enqueued.
 */
class RelayWebhookOutboxAction
{
    public function __construct(
        private readonly SubscriberRegistry $subscriberRegistry,
    ) {}

    /**
     * @param  list<int>|null  $outboxMessageIds  Limits the sweep to these ids; null relays every un-enqueued row.
     * @return int Count of rows actually enqueued during this call.
     */
    final public function execute(?array $outboxMessageIds = null): int
    {
        $unqueuedOutboxMessageQuery = WebhookOutboxMessage::query()->unqueued();

        if ($outboxMessageIds !== null) {
            $unqueuedOutboxMessageQuery->whereIn('id', $outboxMessageIds);
        }

        $enqueuedCount = 0;

        $unqueuedOutboxMessageQuery->lazyById()->each(
            function (WebhookOutboxMessage $outboxMessage) use (&$enqueuedCount): void {
                if ($this->relayOutboxMessage($outboxMessage->id)) {
                    $enqueuedCount++;
                }
            },
        );

        return $enqueuedCount;
    }

    private function relayOutboxMessage(int $outboxMessageId): bool
    {
        try {
            return WebhookOutboxMessage::outboxConnection()->transaction(
                fn (): bool => $this->relayUnqueuedOutboxMessage($outboxMessageId),
            );
        } catch (Throwable $exception) {
            report($exception);

            return false;
        }
    }

    /**
     * Marking the row enqueued when the subscriber is gone (dead-lettering it) is not a successful relay,
     * so it must not count toward the caller's enqueued count.
     */
    private function relayUnqueuedOutboxMessage(int $outboxMessageId): bool
    {
        $outboxMessage = WebhookOutboxMessage::query()
            ->whereKey($outboxMessageId)
            ->unqueued()
            ->lock('for update skip locked')
            ->first();

        if ($outboxMessage === null) {
            return false;
        }

        try {
            $subscriber = $this->subscriberRegistry->find($outboxMessage->subscriber);

            if ($subscriber === null || ! $subscriber->subscribesTo($outboxMessage->event)) {
                WebhookOutboxMessage::recordFinalFailureById(
                    $outboxMessage->id,
                    $outboxMessage->attempts,
                    null,
                    'Webhook subscriber not configured.',
                );
                WebhookOutboxMessage::markEnqueuedById($outboxMessage->id);

                return false;
            }

            $this->dispatchWebhookCall($outboxMessage, $subscriber, $this->contextFor($outboxMessage));
            WebhookOutboxMessage::markEnqueuedById($outboxMessage->id);

            return true;
        } catch (Throwable $exception) {
            Log::error('Webhook outbox relay failed.', [
                ...$this->contextFor($outboxMessage),
                'exception' => $exception::class,
                'exception_message' => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function dispatchWebhookCall(
        WebhookOutboxMessage $outboxMessage,
        Subscriber $subscriber,
        array $context,
    ): void {
        $this->webhookCallFor($outboxMessage, $subscriber)->meta($context)->dispatch();
    }

    /**
     * Extension point for queue, timeout, tries, backoff, proxy, extra headers and the like. An override must not
     * change the payload or the signing: a replay resends the stored body byte-for-byte and receivers verify the
     * `Signature` header against it. The package applies the call's meta after this method, so an override cannot
     * break the outcome recorder or the delivery logger.
     */
    protected function webhookCallFor(WebhookOutboxMessage $outboxMessage, Subscriber $subscriber): OutgoingWebhookCall
    {
        $headerPrefix = config('webhook-outbox.header_prefix');

        return OutgoingWebhookCall::create()
            ->url($subscriber->url)
            ->payload($outboxMessage->payload)
            ->useSecret($subscriber->signingSecret)
            ->useTimestamp()
            ->withHeaders([
                $headerPrefix.'-Event' => $outboxMessage->event,
                $headerPrefix.'-Subscriber' => $subscriber->name,
            ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function contextFor(WebhookOutboxMessage $outboxMessage): array
    {
        return [
            ...$outboxMessage->log_context,
            'event' => $outboxMessage->event,
            'event_id' => $outboxMessage->event_id,
            'subscriber' => $outboxMessage->subscriber,
            'outbox_message_id' => $outboxMessage->id,
        ];
    }
}
