<?php

declare(strict_types=1);

namespace Zakobo\WebhookOutbox\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Zakobo\WebhookOutbox\Enums\WebhookOutboxStatus;
use Zakobo\WebhookOutbox\Models\WebhookOutboxMessage;

/** @extends Factory<WebhookOutboxMessage> */
class WebhookOutboxMessageFactory extends Factory
{
    protected $model = WebhookOutboxMessage::class;

    public function definition(): array
    {
        return [
            'event_id' => $this->faker->uuid(),
            'event' => 'thing.happened',
            'subscriber' => 'auth',
            'url' => 'https://auth.example.test/webhooks',
            'payload' => ['event' => 'thing.happened', 'event_id' => $this->faker->uuid()],
            'log_context' => [],
            'status' => WebhookOutboxStatus::Pending,
            'attempts' => 0,
            'last_status_code' => null,
            'last_error' => null,
            'enqueued_at' => null,
            'delivered_at' => null,
        ];
    }

    public function succeeded(): static
    {
        return $this->state(fn (): array => [
            'status' => WebhookOutboxStatus::Succeeded,
            'attempts' => 1,
            'last_status_code' => 200,
            'enqueued_at' => now(),
            'delivered_at' => now(),
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (): array => [
            'status' => WebhookOutboxStatus::Failed,
            'attempts' => 3,
            'last_status_code' => 503,
            'last_error' => 'Service unavailable',
            'enqueued_at' => now(),
        ]);
    }

    public function enqueued(): static
    {
        return $this->state(fn (): array => [
            'enqueued_at' => now(),
        ]);
    }
}
