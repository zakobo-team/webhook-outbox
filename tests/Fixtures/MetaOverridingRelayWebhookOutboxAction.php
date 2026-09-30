<?php

declare(strict_types=1);

namespace Zakobo\WebhookOutbox\Tests\Fixtures;

use Spatie\WebhookServer\WebhookCall;
use Zakobo\WebhookOutbox\Actions\RelayWebhookOutboxAction;
use Zakobo\WebhookOutbox\Models\WebhookOutboxMessage;
use Zakobo\WebhookOutbox\ValueObjects\Subscriber;

class MetaOverridingRelayWebhookOutboxAction extends RelayWebhookOutboxAction
{
    protected function webhookCallFor(WebhookOutboxMessage $outboxMessage, Subscriber $subscriber): WebhookCall
    {
        return parent::webhookCallFor($outboxMessage, $subscriber)->meta(['outbox_message_id' => 0, 'own' => 'meta']);
    }
}
