<?php

declare(strict_types=1);

namespace Zakobo\WebhookOutbox\Tests\Fixtures;

use Spatie\WebhookServer\Events\DispatchingWebhookCallEvent;
use Spatie\WebhookServer\Events\WebhookCallEvent;
use Zakobo\WebhookOutbox\Listeners\WebhookDeliveryLogger;

class FieldAddingWebhookDeliveryLogger extends WebhookDeliveryLogger
{
    protected function context(DispatchingWebhookCallEvent|WebhookCallEvent $event): array
    {
        return [...parent::context($event), 'added_by_subclass' => true];
    }
}
