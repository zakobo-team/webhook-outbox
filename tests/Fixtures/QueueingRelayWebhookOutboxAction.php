<?php

declare(strict_types=1);

namespace Zakobo\WebhookOutbox\Tests\Fixtures;

use Spatie\WebhookServer\WebhookCall;
use Zakobo\WebhookOutbox\Actions\RelayWebhookOutboxAction;
use Zakobo\WebhookOutbox\Models\WebhookOutboxMessage;
use Zakobo\WebhookOutbox\ValueObjects\Subscriber;

class QueueingRelayWebhookOutboxAction extends RelayWebhookOutboxAction
{
    /** @var list<array{WebhookOutboxMessage, Subscriber}> */
    public static array $customizedFor = [];

    protected function webhookCallFor(WebhookOutboxMessage $outboxMessage, Subscriber $subscriber): WebhookCall
    {
        self::$customizedFor[] = [$outboxMessage, $subscriber];

        return parent::webhookCallFor($outboxMessage, $subscriber)
            ->onQueue('webhooks-priority')
            ->timeoutInSeconds(45)
            ->withHeaders(['X-Custom' => $subscriber->name]);
    }
}
