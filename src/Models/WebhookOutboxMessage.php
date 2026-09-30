<?php

declare(strict_types=1);

namespace Zakobo\Outbox\Models;

use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Zakobo\Outbox\Database\Factories\WebhookOutboxMessageFactory;
use Zakobo\Outbox\Enums\WebhookOutboxStatus;

/**
 * @property int $id
 * @property string $event_id
 * @property string $event
 * @property string $subscriber
 * @property string $url
 * @property array<string, mixed> $payload
 * @property array<string, mixed> $log_context
 * @property WebhookOutboxStatus $status
 * @property int $attempts
 * @property int|null $last_status_code
 * @property string|null $last_error
 * @property Carbon|null $enqueued_at
 * @property Carbon|null $delivered_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[UseFactory(WebhookOutboxMessageFactory::class)]
class WebhookOutboxMessage extends Model
{
    /** @use HasFactory<WebhookOutboxMessageFactory> */
    use HasFactory;

    use MassPrunable;

    protected $fillable = [
        'event_id',
        'event',
        'subscriber',
        'url',
        'payload',
        'log_context',
        'status',
    ];

    public static function outboxConnection(): Connection
    {
        return (new self)->getConnection();
    }

    public function getConnectionName(): ?string
    {
        return config('outbox.connection');
    }

    public function getTable(): string
    {
        return config('outbox.table');
    }

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'log_context' => 'array',
            'status' => WebhookOutboxStatus::class,
            'attempts' => 'integer',
            'last_status_code' => 'integer',
            'enqueued_at' => 'datetime',
            'delivered_at' => 'datetime',
        ];
    }

    /** @param  Builder<WebhookOutboxMessage>  $query */
    #[Scope]
    protected function failed(Builder $query): void
    {
        $query->where('status', WebhookOutboxStatus::Failed);
    }

    /** @param  Builder<WebhookOutboxMessage>  $query */
    #[Scope]
    protected function unqueued(Builder $query): void
    {
        $query->whereNull('enqueued_at');
    }

    /**
     * Outbox message bodies can carry personal contact data, so they are not kept longer than the configured
     * `prune_after_days`.
     *
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return static::query()->where('created_at', '<=', now()->subDays(config('outbox.prune_after_days')));
    }

    public static function recordSuccessById(int $id, int $attempt, ?int $statusCode): void
    {
        self::notYetSucceededQuery($id)->update([
            'status' => WebhookOutboxStatus::Succeeded,
            'attempts' => $attempt,
            'last_status_code' => $statusCode,
            'delivered_at' => now(),
        ]);
    }

    public static function recordFailedAttemptById(int $id, int $attempt, ?int $statusCode, ?string $error): void
    {
        self::notYetSucceededQuery($id)->update([
            'attempts' => $attempt,
            'last_status_code' => $statusCode,
            'last_error' => $error === null ? null : Str::limit($error, 255, ''),
        ]);
    }

    public static function recordFinalFailureById(int $id, int $attempt, ?int $statusCode, ?string $error): void
    {
        self::notYetSucceededQuery($id)->update([
            'status' => WebhookOutboxStatus::Failed,
            'attempts' => $attempt,
            'last_status_code' => $statusCode,
            'last_error' => $error === null ? null : Str::limit($error, 255, ''),
        ]);
    }

    /**
     * Also called after a final failure (e.g. an unconfigured subscriber), which must not be retried.
     */
    public static function markEnqueuedById(int $id): void
    {
        static::query()->whereKey($id)->update(['enqueued_at' => now()]);
    }

    /**
     * A resend is safe: receivers dedupe on `event_id`. Without `$onlyWhenFailed` there is no competing claim
     * to lose, so the reset always applies regardless of whether it changed any column value.
     */
    public function claimForReplay(string $url, bool $onlyWhenFailed): bool
    {
        $query = static::query()->whereKey($this->getKey());

        $resetValues = [
            'status' => WebhookOutboxStatus::Pending,
            'url' => $url,
            'enqueued_at' => null,
            'attempts' => 0,
            'last_status_code' => null,
            'last_error' => null,
        ];

        if (! $onlyWhenFailed) {
            $query->update($resetValues);

            return true;
        }

        return $query->failed()->update($resetValues) === 1;
    }

    /** @return Builder<static> */
    private static function notYetSucceededQuery(int $id): Builder
    {
        return static::query()
            ->whereKey($id)
            ->where('status', '!=', WebhookOutboxStatus::Succeeded);
    }
}
