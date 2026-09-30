<?php

declare(strict_types=1);

namespace Zakobo\WebhookOutbox\Tests\Fixtures;

use Zakobo\WebhookOutbox\Listeners\WebhookDeliveryLogger;

class LogOverridingWebhookDeliveryLogger extends WebhookDeliveryLogger
{
    /** @var list<array{string, string, array<string, mixed>}> */
    public static array $logged = [];

    protected function log(string $level, string $message, array $context): void
    {
        self::$logged[] = [$level, $message, $context];
    }
}
