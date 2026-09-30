<?php

declare(strict_types=1);

namespace Zakobo\Outbox\Tests\Unit\ValueObjects;

use PHPUnit\Framework\Attributes\Test;
use Zakobo\Outbox\Exceptions\InvalidWebhookConfigException;
use Zakobo\Outbox\Tests\TestCase;
use Zakobo\Outbox\ValueObjects\Subscriber;

final class SubscriberTest extends TestCase
{
    #[Test]
    public function a_star_events_subscriber_subscribes_to_any_event_name(): void
    {
        $subscriber = new Subscriber('auth', 'https://auth.example.test', 'secret', '*');

        $this->assertTrue($subscriber->subscribesTo('thing.created'));
        $this->assertTrue($subscriber->subscribesTo('thing.anything-at-all'));
    }

    #[Test]
    public function a_listed_events_subscriber_only_subscribes_to_its_declared_events(): void
    {
        $subscriber = new Subscriber('auth', 'https://auth.example.test', 'secret', ['thing.created', 'thing.updated']);

        $this->assertTrue($subscriber->subscribesTo('thing.created'));
        $this->assertTrue($subscriber->subscribesTo('thing.updated'));
        $this->assertFalse($subscriber->subscribesTo('thing.deleted'));
    }

    #[Test]
    public function an_empty_events_list_subscribes_to_nothing(): void
    {
        $subscriber = new Subscriber('auth', 'https://auth.example.test', 'secret', []);

        $this->assertFalse($subscriber->subscribesTo('thing.created'));
    }

    #[Test]
    public function from_config_builds_a_star_events_subscriber(): void
    {
        $subscriber = Subscriber::fromConfig('auth', [
            'url' => 'https://auth.example.test/webhooks',
            'signing_secret' => 'auth-secret',
            'events' => '*',
        ]);

        $this->assertInstanceOf(Subscriber::class, $subscriber);
        $this->assertSame('auth', $subscriber->name);
        $this->assertSame('https://auth.example.test/webhooks', $subscriber->url);
        $this->assertSame('auth-secret', $subscriber->signingSecret);
        $this->assertSame('*', $subscriber->events);
    }

    #[Test]
    public function from_config_builds_a_listed_events_subscriber(): void
    {
        $subscriber = Subscriber::fromConfig('auth', [
            'url' => 'https://auth.example.test/webhooks',
            'signing_secret' => 'auth-secret',
            'events' => ['thing.created', 'thing.updated'],
        ]);

        $this->assertInstanceOf(Subscriber::class, $subscriber);
        $this->assertSame(['thing.created', 'thing.updated'], $subscriber->events);
    }

    #[Test]
    public function from_config_returns_null_for_a_blank_url(): void
    {
        $subscriber = Subscriber::fromConfig('auth', [
            'url' => '',
            'signing_secret' => '',
            'events' => '*',
        ]);

        $this->assertNull($subscriber);
    }

    #[Test]
    public function from_config_still_validates_events_for_a_blank_url_subscriber(): void
    {
        $this->expectException(InvalidWebhookConfigException::class);
        $this->expectExceptionMessage(
            "Webhook subscriber [auth] must declare which events it subscribes to ('*' or a list of event names).",
        );

        Subscriber::fromConfig('auth', ['url' => '', 'signing_secret' => '']);
    }

    #[Test]
    public function from_config_throws_for_a_missing_events_key(): void
    {
        $this->expectException(InvalidWebhookConfigException::class);
        $this->expectExceptionMessage(
            "Webhook subscriber [auth] must declare which events it subscribes to ('*' or a list of event names).",
        );

        Subscriber::fromConfig('auth', ['url' => 'https://auth.example.test/webhooks', 'signing_secret' => 'secret']);
    }

    #[Test]
    public function from_config_throws_for_a_non_string_non_array_events_value(): void
    {
        $this->expectException(InvalidWebhookConfigException::class);

        Subscriber::fromConfig('auth', [
            'url' => 'https://auth.example.test/webhooks',
            'signing_secret' => 'secret',
            'events' => 42,
        ]);
    }

    #[Test]
    public function from_config_throws_when_an_events_list_entry_is_not_a_string(): void
    {
        $this->expectException(InvalidWebhookConfigException::class);

        Subscriber::fromConfig('auth', [
            'url' => 'https://auth.example.test/webhooks',
            'signing_secret' => 'secret',
            'events' => ['thing.created', 42],
        ]);
    }

    #[Test]
    public function from_config_throws_for_a_url_without_a_signing_secret(): void
    {
        $this->expectException(InvalidWebhookConfigException::class);
        $this->expectExceptionMessage('Webhook subscriber [auth] has a URL but no signing secret.');

        Subscriber::fromConfig('auth', [
            'url' => 'https://auth.example.test/webhooks',
            'signing_secret' => '',
            'events' => '*',
        ]);
    }
}
