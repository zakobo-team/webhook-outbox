<?php

declare(strict_types=1);

namespace Zakobo\WebhookOutbox\Tests\Feature;

use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;
use PHPUnit\Framework\Attributes\Test;
use Zakobo\WebhookOutbox\Exceptions\InvalidWebhookConfigException;
use Zakobo\WebhookOutbox\Models\WebhookOutboxMessage;
use Zakobo\WebhookOutbox\Support\SubscriberRegistry;
use Zakobo\WebhookOutbox\Tests\TestCase;
use Zakobo\WebhookOutbox\WebhookOutboxServiceProvider;

final class WebhookOutboxServiceProviderTest extends TestCase
{
    #[Test]
    public function the_shipped_config_defaults_are_merged(): void
    {
        $this->assertNull(config('webhook-outbox.connection'));
        $this->assertSame('webhook_outbox', config('webhook-outbox.table'));
        $this->assertSame('X-Zakobo-Webhook', config('webhook-outbox.header_prefix'));
        $this->assertSame(90, config('webhook-outbox.prune_after_days'));
        $this->assertSame([], config('webhook-outbox.subscribers'));
    }

    #[Test]
    public function the_subscriber_registry_resolves_from_the_configured_subscribers(): void
    {
        config(['webhook-outbox.subscribers' => [
            'auth' => ['url' => 'https://auth.example.test/webhooks', 'signing_secret' => 'secret', 'events' => '*'],
        ]]);

        $this->assertNotNull(app(SubscriberRegistry::class)->find('auth'));
    }

    #[Test]
    public function booting_the_provider_with_an_invalid_config_fails_the_boot(): void
    {
        config(['webhook-outbox.subscribers' => ['auth' => ['url' => 'https://auth.example.test/webhooks']]]);

        $this->expectException(InvalidWebhookConfigException::class);
        $this->expectExceptionMessage('Webhook subscriber [auth] must declare which events it subscribes to');

        (new WebhookOutboxServiceProvider($this->app))->boot();
    }

    #[Test]
    public function the_package_leaves_scheduling_webhooks_relay_to_the_application(): void
    {
        $scheduledCommands = collect($this->app->make(Schedule::class)->events())->pluck('command');

        $this->assertFalse($scheduledCommands->contains(
            fn (?string $command): bool => $command !== null && str_contains($command, 'webhooks:relay'),
        ));
    }

    #[Test]
    public function the_outbox_prune_is_scheduled_daily_without_overlapping(): void
    {
        $pruneEvent = $this->scheduledEventContaining('model:prune');

        $this->assertStringContainsString(WebhookOutboxMessage::class, $pruneEvent->command);
        $this->assertSame('0 0 * * *', $pruneEvent->expression);
        $this->assertTrue($pruneEvent->withoutOverlapping);
    }

    #[Test]
    public function the_config_and_the_migration_are_publishable_under_their_own_tags(): void
    {
        $configPaths = ServiceProvider::pathsToPublish(WebhookOutboxServiceProvider::class, 'webhook-outbox-config');
        $migrationPaths = ServiceProvider::pathsToPublish(
            WebhookOutboxServiceProvider::class,
            'webhook-outbox-migrations',
        );

        $this->assertSame([config_path('webhook-outbox.php')], array_values($configPaths));
        $this->assertCount(1, $migrationPaths);
        $this->assertMatchesRegularExpression(
            '/migrations\/\d{4}_\d{2}_\d{2}_\d{6}_create_webhook_outbox_table\.php$/',
            array_values($migrationPaths)[0],
        );
    }

    private function scheduledEventContaining(string $command): ScheduledEvent
    {
        $scheduledEvent = collect($this->app->make(Schedule::class)->events())
            ->first(fn (ScheduledEvent $event): bool => is_string($event->command)
                && str_contains($event->command, $command));

        $this->assertInstanceOf(ScheduledEvent::class, $scheduledEvent);

        return $scheduledEvent;
    }
}
