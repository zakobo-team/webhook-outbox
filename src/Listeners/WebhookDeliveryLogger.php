<?php

declare(strict_types=1);

namespace Zakobo\Outbox\Listeners;

use Illuminate\Support\Facades\Log;
use Spatie\WebhookServer\Events\DispatchingWebhookCallEvent;
use Spatie\WebhookServer\Events\FinalWebhookCallFailedEvent;
use Spatie\WebhookServer\Events\WebhookCallEvent;
use Spatie\WebhookServer\Events\WebhookCallFailedEvent;
use Spatie\WebhookServer\Events\WebhookCallSucceededEvent;

final class WebhookDeliveryLogger
{
    public function handleDispatchingWebhookCallEvent(DispatchingWebhookCallEvent $event): void
    {
        $this->log('info', 'Webhook dispatching.', $event);
    }

    public function handleWebhookCallSucceededEvent(WebhookCallSucceededEvent $event): void
    {
        $this->log('info', 'Webhook delivered.', $event);
    }

    public function handleWebhookCallFailedEvent(WebhookCallFailedEvent $event): void
    {
        $this->log('warning', 'Webhook delivery failed.', $event);
    }

    public function handleFinalWebhookCallFailedEvent(FinalWebhookCallFailedEvent $event): void
    {
        $this->log('critical', 'Webhook final delivery failed.', $event);
    }

    private function log(string $level, string $message, DispatchingWebhookCallEvent|WebhookCallEvent $event): void
    {
        if (! $this->isOurs($event->meta)) {
            return;
        }

        Log::{$level}($message, $this->context($event));
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function isOurs(array $meta): bool
    {
        return array_key_exists('event_id', $meta) && array_key_exists('subscriber', $meta);
    }

    /**
     * @return array<string, mixed>
     */
    private function context(DispatchingWebhookCallEvent|WebhookCallEvent $event): array
    {
        $meta = $event->meta;
        $callerLogContext = array_diff_key($meta, array_flip(['event', 'event_id', 'subscriber']));

        return [
            ...$callerLogContext,
            'event' => $meta['event'] ?? null,
            'event_id' => $meta['event_id'],
            'subscriber' => $meta['subscriber'],
            'webhook_uuid' => $event->uuid,
            'endpoint_host' => parse_url($event->webhookUrl, PHP_URL_HOST),
            'endpoint_path' => parse_url($event->webhookUrl, PHP_URL_PATH),
            'attempt' => $event instanceof WebhookCallEvent ? $event->attempt : null,
            'status_code' => $event instanceof WebhookCallEvent ? $event->response?->getStatusCode() : null,
            'exception' => $event instanceof WebhookCallEvent ? $event->errorType : null,
            'exception_message' => $event instanceof WebhookCallEvent ? $event->errorMessage : null,
        ];
    }
}
