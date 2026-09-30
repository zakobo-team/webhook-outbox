<?php

declare(strict_types=1);

namespace Zakobo\Outbox;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Spatie\WebhookServer\Events\DispatchingWebhookCallEvent;
use Spatie\WebhookServer\Events\FinalWebhookCallFailedEvent;
use Spatie\WebhookServer\Events\WebhookCallFailedEvent;
use Spatie\WebhookServer\Events\WebhookCallSucceededEvent;
use Zakobo\Outbox\Console\Commands\RelayWebhookOutboxCommand;
use Zakobo\Outbox\Console\Commands\ReplayWebhookOutboxCommand;
use Zakobo\Outbox\Listeners\RecordWebhookOutboxOutcome;
use Zakobo\Outbox\Listeners\WebhookDeliveryLogger;
use Zakobo\Outbox\Models\WebhookOutboxMessage;
use Zakobo\Outbox\Support\SubscriberRegistry;

class OutboxServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/outbox.php', 'outbox');

        $this->app->bind(
            SubscriberRegistry::class,
            fn (): SubscriberRegistry => SubscriberRegistry::fromConfig(config('outbox.subscribers')),
        );
    }

    /**
     * Eagerly resolved so an invalid subscribers config fails the boot instead of the first request or job
     * that happens to dispatch a webhook.
     */
    public function boot(): void
    {
        $this->app->make(SubscriberRegistry::class);

        $this->registerListeners();
        $this->registerSchedule();

        if ($this->app->runningInConsole()) {
            $this->commands([
                RelayWebhookOutboxCommand::class,
                ReplayWebhookOutboxCommand::class,
            ]);

            $this->publishes([__DIR__.'/../config/outbox.php' => config_path('outbox.php')], 'outbox-config');
            $this->publishes([
                __DIR__.'/../database/migrations/create_webhook_outbox_table.php' => database_path(
                    'migrations/'.date('Y_m_d_His').'_create_webhook_outbox_table.php',
                ),
            ], 'outbox-migrations');
        }
    }

    private function registerListeners(): void
    {
        $listeners = [
            [WebhookCallSucceededEvent::class, RecordWebhookOutboxOutcome::class, 'handleWebhookCallSucceededEvent'],
            [WebhookCallFailedEvent::class, RecordWebhookOutboxOutcome::class, 'handleWebhookCallFailedEvent'],
            [
                FinalWebhookCallFailedEvent::class,
                RecordWebhookOutboxOutcome::class,
                'handleFinalWebhookCallFailedEvent',
            ],
            [DispatchingWebhookCallEvent::class, WebhookDeliveryLogger::class, 'handleDispatchingWebhookCallEvent'],
            [WebhookCallSucceededEvent::class, WebhookDeliveryLogger::class, 'handleWebhookCallSucceededEvent'],
            [WebhookCallFailedEvent::class, WebhookDeliveryLogger::class, 'handleWebhookCallFailedEvent'],
            [FinalWebhookCallFailedEvent::class, WebhookDeliveryLogger::class, 'handleFinalWebhookCallFailedEvent'],
        ];

        foreach ($listeners as [$eventClass, $listenerClass, $method]) {
            Event::listen($eventClass, [$listenerClass, $method]);
        }
    }

    private function registerSchedule(): void
    {
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('webhooks:relay')->everyMinute()->withoutOverlapping();
            $schedule->command('model:prune', ['--model' => [WebhookOutboxMessage::class]])
                ->daily()
                ->withoutOverlapping();
        });
    }
}
