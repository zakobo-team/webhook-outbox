<?php

declare(strict_types=1);

namespace Zakobo\Outbox\Tests\Unit\Support;

use PHPUnit\Framework\Attributes\Test;
use Zakobo\Outbox\Exceptions\InvalidWebhookConfigException;
use Zakobo\Outbox\Support\SubscriberRegistry;
use Zakobo\Outbox\Tests\TestCase;
use Zakobo\Outbox\ValueObjects\Subscriber;

final class SubscriberRegistryTest extends TestCase
{
    #[Test]
    public function a_subscribers_config_that_is_not_an_array_throws(): void
    {
        $this->expectException(InvalidWebhookConfigException::class);
        $this->expectExceptionMessage('Webhook subscribers config must be an array.');

        SubscriberRegistry::fromConfig('not-an-array');
    }

    #[Test]
    public function a_subscriber_entry_that_is_not_an_array_throws(): void
    {
        $this->expectException(InvalidWebhookConfigException::class);
        $this->expectExceptionMessage('Webhook subscriber [auth] must be configured as an array.');

        SubscriberRegistry::fromConfig(['auth' => 'https://auth.example.test/webhooks']);
    }

    #[Test]
    public function subscribers_for_only_returns_subscribers_subscribed_to_the_event(): void
    {
        $registry = SubscriberRegistry::fromConfig([
            'auth' => [
                'url' => 'https://auth.example.test/webhooks',
                'signing_secret' => 'auth-secret',
                'events' => '*',
            ],
            'accounting' => [
                'url' => 'https://accounting.example.test/webhooks',
                'signing_secret' => 'accounting-secret',
                'events' => ['thing.created'],
            ],
        ]);

        $this->assertSame(['auth'], array_keys($registry->subscribersFor('thing.updated')));
        $this->assertSame(['auth', 'accounting'], array_keys($registry->subscribersFor('thing.created')));
    }

    #[Test]
    public function find_returns_the_named_subscriber_or_null(): void
    {
        $registry = SubscriberRegistry::fromConfig([
            'auth' => ['url' => 'https://auth.example.test/webhooks', 'signing_secret' => 'secret', 'events' => '*'],
        ]);

        $this->assertInstanceOf(Subscriber::class, $registry->find('auth'));
        $this->assertNull($registry->find('accounting'));
    }
}
