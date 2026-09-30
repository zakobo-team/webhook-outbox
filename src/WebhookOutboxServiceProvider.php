<?php

declare(strict_types=1);

namespace Zakobo\WebhookOutbox;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Spatie\WebhookServer\Events\DispatchingWebhookCallEvent;
use Spatie\WebhookServer\Events\FinalWebhookCallFailedEvent;
use Spatie\WebhookServer\Events\WebhookCallFailedEvent;
use Spatie\WebhookServer\Events\WebhookCallSucceededEvent;
use Zakobo\WebhookOutbox\Actions\RelayWebhookOutboxAction;
use Zakobo\WebhookOutbox\Console\Commands\RelayWebhookOutboxCommand;
use Zakobo\WebhookOutbox\Console\Commands\ReplayWebhookOutboxCommand;
use Zakobo\WebhookOutbox\Exceptions\InvalidWebhookConfigException;
use Zakobo\WebhookOutbox\Listeners\RecordWebhookOutboxOutcome;
use Zakobo\WebhookOutbox\Listeners\WebhookDeliveryLogger;
use Zakobo\WebhookOutbox\Models\WebhookOutboxMessage;
use Zakobo\WebhookOutbox\Support\SubscriberRegistry;

class WebhookOutboxServiceProvider extends ServiceProvider
{
    private const array SWAPPABLE_CLASSES = [
        'relay_action' => RelayWebhookOutboxAction::class,
        'delivery_logger' => WebhookDeliveryLogger::class,
    ];

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/webhook-outbox.php', 'webhook-outbox');

        $this->app->bind(
            SubscriberRegistry::class,
            fn (): SubscriberRegistry => SubscriberRegistry::fromConfig(config('webhook-outbox.subscribers')),
        );

        foreach (self::SWAPPABLE_CLASSES as $configKey => $originalClass) {
            $this->app->bind(
                $originalClass,
                fn (Application $app): object => $app->build(self::configuredClass($configKey, $originalClass)),
            );
        }
    }

    /**
     * Eagerly validated so an invalid config fails the boot instead of the first request or job that happens to
     * dispatch a webhook.
     */
    public function boot(): void
    {
        $this->app->make(SubscriberRegistry::class);
        $this->validateSwappableClasses();

        $this->registerListeners();
        $this->registerSchedule();

        if ($this->app->runningInConsole()) {
            $this->commands([
                RelayWebhookOutboxCommand::class,
                ReplayWebhookOutboxCommand::class,
            ]);

            $this->publishes(
                [__DIR__.'/../config/webhook-outbox.php' => config_path('webhook-outbox.php')],
                'webhook-outbox-config',
            );
            $this->publishes([
                __DIR__.'/../database/migrations/create_webhook_outbox_table.php' => database_path(
                    'migrations/'.date('Y_m_d_His').'_create_webhook_outbox_table.php',
                ),
            ], 'webhook-outbox-migrations');
        }
    }

    private function validateSwappableClasses(): void
    {
        foreach (self::SWAPPABLE_CLASSES as $configKey => $originalClass) {
            $configuredClass = config("webhook-outbox.classes.{$configKey}", $originalClass);

            if (! is_string($configuredClass) || ! is_a($configuredClass, $originalClass, true)) {
                throw new InvalidWebhookConfigException(
                    "Config [webhook-outbox.classes.{$configKey}] must be [{$originalClass}] or a class extending it."
                );
            }
        }
    }

    /**
     * @template TClass of object
     *
     * @param  class-string<TClass>  $originalClass
     * @return class-string<TClass>
     */
    private static function configuredClass(string $configKey, string $originalClass): string
    {
        $configuredClass = config("webhook-outbox.classes.{$configKey}", $originalClass);

        return is_string($configuredClass) && is_a($configuredClass, $originalClass, true)
            ? $configuredClass
            : $originalClass;
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
            $schedule->command('model:prune', ['--model' => [WebhookOutboxMessage::class]])
                ->daily()
                ->withoutOverlapping();
        });
    }
}
