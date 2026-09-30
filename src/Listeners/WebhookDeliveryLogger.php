<?php

declare(strict_types=1);

namespace Zakobo\WebhookOutbox\Listeners;

use Illuminate\Support\Facades\Log;
use Spatie\WebhookServer\Events\DispatchingWebhookCallEvent;
use Spatie\WebhookServer\Events\FinalWebhookCallFailedEvent;
use Spatie\WebhookServer\Events\WebhookCallEvent;
use Spatie\WebhookServer\Events\WebhookCallFailedEvent;
use Spatie\WebhookServer\Events\WebhookCallSucceededEvent;

class WebhookDeliveryLogger
{
    public function handleDispatchingWebhookCallEvent(DispatchingWebhookCallEvent $event): void
    {
        $this->logIfOurs('info', 'Webhook dispatching.', $event);
    }

    public function handleWebhookCallSucceededEvent(WebhookCallSucceededEvent $event): void
    {
        $this->logIfOurs('info', 'Webhook delivered.', $event);
    }

    public function handleWebhookCallFailedEvent(WebhookCallFailedEvent $event): void
    {
        $this->logIfOurs('warning', 'Webhook delivery failed.', $event);
    }

    public function handleFinalWebhookCallFailedEvent(FinalWebhookCallFailedEvent $event): void
    {
        $this->logIfOurs('critical', 'Webhook final delivery failed.', $event);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    protected function log(string $level, string $message, array $context): void
    {
        Log::{$level}($message, $context);
    }

    private function logIfOurs(
        string $level,
        string $message,
        DispatchingWebhookCallEvent|WebhookCallEvent $event,
    ): void {
        if (! $this->isOurs($event->meta)) {
            return;
        }

        $this->log($level, $message, $this->context($event));
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
    protected function context(DispatchingWebhookCallEvent|WebhookCallEvent $event): array
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
