<?php

declare(strict_types=1);

namespace Zakobo\WebhookOutbox\Exceptions;

use RuntimeException;

class WebhookSubscriberNotConfiguredException extends RuntimeException
{
    public static function forOutboxMessage(string $subscriber, string $event): self
    {
        return new self("Webhook subscriber [{$subscriber}] is not configured for the [{$event}] event.");
    }
}
