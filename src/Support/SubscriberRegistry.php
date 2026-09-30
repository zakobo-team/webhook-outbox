<?php

declare(strict_types=1);

namespace Zakobo\WebhookOutbox\Support;

use Zakobo\WebhookOutbox\Exceptions\InvalidWebhookConfigException;
use Zakobo\WebhookOutbox\ValueObjects\Subscriber;

final readonly class SubscriberRegistry
{
    /**
     * @param  array<string, Subscriber>  $subscribers
     */
    public function __construct(
        private array $subscribers,
    ) {}

    public static function fromConfig(mixed $subscriberConfigs): self
    {
        if (! is_array($subscriberConfigs)) {
            throw new InvalidWebhookConfigException('Webhook subscribers config must be an array.');
        }

        return new self(collect($subscriberConfigs)
            ->map(fn (mixed $config, string|int $name): ?Subscriber => Subscriber::fromConfig((string) $name, $config))
            ->filter()
            ->all());
    }

    /**
     * @return array<string, Subscriber>
     */
    public function subscribersFor(string $eventName): array
    {
        return array_filter(
            $this->subscribers,
            fn (Subscriber $subscriber): bool => $subscriber->subscribesTo($eventName),
        );
    }

    public function find(string $name): ?Subscriber
    {
        return $this->subscribers[$name] ?? null;
    }
}
