<?php

declare(strict_types=1);

namespace Zakobo\Outbox\Console\Commands;

use Illuminate\Console\Command;
use Throwable;
use Zakobo\Outbox\Actions\ReplayWebhookOutboxMessageAction;
use Zakobo\Outbox\Models\WebhookOutboxMessage;

final class ReplayWebhookOutboxCommand extends Command
{
    protected $signature = 'webhooks:replay
                            {messages?* : Outbox message id(s) to replay}
                            {--failed : Replay every failed outbox message}';

    protected $description = 'Replay outbox messages verbatim (same event_id and body) to their subscriber';

    public function handle(ReplayWebhookOutboxMessageAction $replayWebhookOutboxMessage): int
    {
        $rawOutboxMessageIds = $this->argument('messages');

        if ($rawOutboxMessageIds !== [] && $this->option('failed')) {
            $this->error('Provide outbox message ids or pass --failed, not both.');

            return self::FAILURE;
        }

        if ($rawOutboxMessageIds === [] && ! $this->option('failed')) {
            $this->error('Provide one or more outbox message ids, or pass --failed.');

            return self::FAILURE;
        }

        if ($rawOutboxMessageIds === []) {
            return $this->replayEveryFailedOutboxMessage($replayWebhookOutboxMessage);
        }

        $outboxMessageIds = $this->validatedOutboxMessageIds($rawOutboxMessageIds);

        if ($outboxMessageIds === null) {
            return self::FAILURE;
        }

        return $this->replayByIds($outboxMessageIds, $replayWebhookOutboxMessage);
    }

    /**
     * @param  list<mixed>  $rawOutboxMessageIds
     * @return list<int>|null
     */
    private function validatedOutboxMessageIds(array $rawOutboxMessageIds): ?array
    {
        $invalidOutboxMessageIds = array_filter(
            $rawOutboxMessageIds,
            fn (mixed $rawOutboxMessageId): bool => filter_var(
                $rawOutboxMessageId,
                FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 1]],
            ) === false,
        );

        if ($invalidOutboxMessageIds !== []) {
            $this->error('Outbox message ids must be positive integers: '.implode(', ', $invalidOutboxMessageIds).'.');

            return null;
        }

        return collect($rawOutboxMessageIds)->map(fn (mixed $rawOutboxMessageId): int => (int) $rawOutboxMessageId)
            ->unique()
            ->values()
            ->all();
    }

    /** @param  list<int>  $outboxMessageIds */
    private function replayByIds(
        array $outboxMessageIds,
        ReplayWebhookOutboxMessageAction $replayWebhookOutboxMessage,
    ): int {
        $outboxMessagesById = WebhookOutboxMessage::query()->whereIn('id', $outboxMessageIds)->get()->keyBy('id');
        $missingOutboxMessageIds = collect($outboxMessageIds)->diff($outboxMessagesById->keys());

        if ($missingOutboxMessageIds->isNotEmpty()) {
            $this->error('Unknown outbox message id(s): '.$missingOutboxMessageIds->implode(', ').'.');

            return self::FAILURE;
        }

        $exitCode = self::SUCCESS;

        foreach ($outboxMessageIds as $outboxMessageId) {
            if (! $this->replayOne($outboxMessagesById->get($outboxMessageId), $replayWebhookOutboxMessage, false)) {
                $exitCode = self::FAILURE;
            }
        }

        return $exitCode;
    }

    private function replayEveryFailedOutboxMessage(ReplayWebhookOutboxMessageAction $replayWebhookOutboxMessage): int
    {
        $exitCode = self::SUCCESS;

        WebhookOutboxMessage::query()
            ->failed()
            ->lazyById()
            ->each(function (WebhookOutboxMessage $outboxMessage) use ($replayWebhookOutboxMessage, &$exitCode): void {
                if (! $this->replayOne($outboxMessage, $replayWebhookOutboxMessage, true)) {
                    $exitCode = self::FAILURE;
                }
            });

        return $exitCode;
    }

    private function replayOne(
        WebhookOutboxMessage $outboxMessage,
        ReplayWebhookOutboxMessageAction $replayWebhookOutboxMessage,
        bool $onlyWhenFailed,
    ): bool {
        try {
            $wasClaimed = $replayWebhookOutboxMessage->execute($outboxMessage, $onlyWhenFailed);
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return false;
        }

        if (! $wasClaimed) {
            $this->line("Outbox message [{$outboxMessage->id}] is no longer eligible for replay; skipping.");

            return true;
        }

        $this->line("Replayed outbox message [{$outboxMessage->id}] to subscriber [{$outboxMessage->subscriber}].");

        return true;
    }
}
