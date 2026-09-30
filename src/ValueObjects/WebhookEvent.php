<?php

declare(strict_types=1);

namespace Zakobo\Outbox\ValueObjects;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use InvalidArgumentException;

final readonly class WebhookEvent
{
    private const array RESERVED_PAYLOAD_KEYS = ['event', 'event_id', 'occurred_at'];

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, scalar|null>  $logContext
     */
    public function __construct(
        public string $name,
        public string $eventId,
        public CarbonImmutable $occurredAt,
        public array $payload,
        public array $logContext = [],
    ) {
        $reservedKeysUsedByPayload = collect($payload)->only(self::RESERVED_PAYLOAD_KEYS)->keys();

        if ($reservedKeysUsedByPayload->isNotEmpty()) {
            throw new InvalidArgumentException(
                'Webhook event payload must not use the reserved key(s): '
                .$reservedKeysUsedByPayload->implode(', ').'.',
            );
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, scalar|null>  $logContext
     */
    public static function occurNow(
        string $name,
        array $payload,
        array $logContext = [],
    ): self {
        return new self(
            $name,
            Str::uuid()->toString(),
            CarbonImmutable::now(),
            $payload,
            $logContext,
        );
    }

    /**
     * logContext carries caller context for delivery logs and is never sent to the receiving endpoint.
     *
     * @return array<string, mixed>
     */
    public function body(): array
    {
        return [
            'event' => $this->name,
            'event_id' => $this->eventId,
            'occurred_at' => $this->occurredAt->toIso8601String(),
            ...$this->payload,
        ];
    }
}
