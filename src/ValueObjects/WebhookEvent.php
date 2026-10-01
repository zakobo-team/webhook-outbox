<?php

declare(strict_types=1);

namespace Zakobo\WebhookOutbox\ValueObjects;

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
            // ponytail: app-server wall clock, so ordering assumes clock skew below the entity-lock handoff, and two
            // events built for one entity within the same microsecond tie (receivers keep the first). Stamp from the
            // database clock (`now(6)`) if senders ever run on hosts with real skew.
            'occurred_at' => $this->occurredAt->format('Y-m-d\TH:i:s.uP'),
            ...$this->payload,
        ];
    }
}
