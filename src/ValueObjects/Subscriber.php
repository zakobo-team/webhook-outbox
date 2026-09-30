<?php

declare(strict_types=1);

namespace Zakobo\Outbox\ValueObjects;

use Zakobo\Outbox\Exceptions\InvalidWebhookConfigException;

final readonly class Subscriber
{
    /**
     * @param  '*'|list<string>  $events
     */
    public function __construct(
        public string $name,
        public string $url,
        public string $signingSecret,
        public string|array $events,
    ) {}

    /**
     * Null means the subscriber has no URL configured for this environment and is not sent to. The events shape
     * is still validated before that check, so a malformed entry fails even when it would otherwise be skipped.
     */
    public static function fromConfig(string $name, mixed $config): ?self
    {
        if (! is_array($config)) {
            throw new InvalidWebhookConfigException("Webhook subscriber [{$name}] must be configured as an array.");
        }

        $events = self::validatedEvents($name, $config['events'] ?? null);
        $url = trim((string) ($config['url'] ?? ''));

        if ($url === '') {
            return null;
        }

        $signingSecret = trim((string) ($config['signing_secret'] ?? ''));

        if ($signingSecret === '') {
            throw new InvalidWebhookConfigException("Webhook subscriber [{$name}] has a URL but no signing secret.");
        }

        return new self($name, $url, $signingSecret, $events);
    }

    public function subscribesTo(string $eventName): bool
    {
        return $this->events === '*' || in_array($eventName, $this->events, true);
    }

    /**
     * @return '*'|list<string>
     */
    private static function validatedEvents(string $name, mixed $events): string|array
    {
        if ($events === '*') {
            return '*';
        }

        if (! is_array($events) || ! array_all($events, fn (mixed $event): bool => is_string($event))) {
            throw new InvalidWebhookConfigException(
                "Webhook subscriber [{$name}] must declare which events it subscribes to "
                ."('*' or a list of event names).",
            );
        }

        return array_values($events);
    }
}
