<?php

declare(strict_types=1);

namespace Zakobo\Outbox\Listeners;

use Closure;
use Spatie\WebhookServer\Events\FinalWebhookCallFailedEvent;
use Spatie\WebhookServer\Events\WebhookCallEvent;
use Spatie\WebhookServer\Events\WebhookCallFailedEvent;
use Spatie\WebhookServer\Events\WebhookCallSucceededEvent;
use Throwable;
use Zakobo\Outbox\Models\WebhookOutboxMessage;

/**
 * Spatie's CallWebhookJob dispatches WebhookCallSucceededEvent inside its own try/catch(Exception): a throwable
 * escaping this listener would make the job think delivery failed and resend an already-succeeded webhook. Every
 * handler is therefore guarded and reports instead of throwing.
 */
final class RecordWebhookOutboxOutcome
{
    public function handleWebhookCallSucceededEvent(WebhookCallSucceededEvent $event): void
    {
        $this->record($event, function (int $outboxMessageId) use ($event): void {
            WebhookOutboxMessage::recordSuccessById($outboxMessageId, $event->attempt, $this->statusCodeFor($event));
        });
    }

    public function handleWebhookCallFailedEvent(WebhookCallFailedEvent $event): void
    {
        $this->record($event, function (int $outboxMessageId) use ($event): void {
            WebhookOutboxMessage::recordFailedAttemptById(
                $outboxMessageId,
                $event->attempt,
                $this->statusCodeFor($event),
                $event->errorMessage,
            );
        });
    }

    public function handleFinalWebhookCallFailedEvent(FinalWebhookCallFailedEvent $event): void
    {
        $this->record($event, function (int $outboxMessageId) use ($event): void {
            WebhookOutboxMessage::recordFinalFailureById(
                $outboxMessageId,
                $event->attempt,
                $this->statusCodeFor($event),
                $event->errorMessage,
            );
        });
    }

    /**
     * @param  Closure(int): void  $write
     */
    private function record(WebhookCallEvent $event, Closure $write): void
    {
        try {
            $outboxMessageId = $this->outboxMessageIdFor($event);

            if ($outboxMessageId !== null) {
                $write($outboxMessageId);
            }
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    private function outboxMessageIdFor(WebhookCallEvent $event): ?int
    {
        $outboxMessageId = $event->meta['outbox_message_id'] ?? null;

        return is_int($outboxMessageId) ? $outboxMessageId : null;
    }

    private function statusCodeFor(WebhookCallEvent $event): ?int
    {
        return $event->response?->getStatusCode();
    }
}
