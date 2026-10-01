<?php

declare(strict_types=1);

namespace Zakobo\WebhookOutbox\Support;

use Spatie\WebhookServer\BackoffStrategy\BackoffStrategy;

/**
 * 10s, 1m, 5m, 30m, then hourly.
 */
final class WebhookBackoffStrategy implements BackoffStrategy
{
    public const int MAXIMUM_TRIES = 24;

    public function waitInSecondsAfterAttempt(int $attempt): int
    {
        return [10, 60, 300, 1800][$attempt - 1] ?? 3600;
    }
}
