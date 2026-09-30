<?php

declare(strict_types=1);

namespace Zakobo\WebhookOutbox\Tests\Feature;

use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Spatie\WebhookServer\CallWebhookJob;
use Spatie\WebhookServer\Events\WebhookCallSucceededEvent;
use stdClass;
use Zakobo\WebhookOutbox\Actions\DispatchWebhookAction;
use Zakobo\WebhookOutbox\Actions\RelayWebhookOutboxAction;
use Zakobo\WebhookOutbox\Actions\ReplayWebhookOutboxMessageAction;
use Zakobo\WebhookOutbox\Enums\WebhookOutboxStatus;
use Zakobo\WebhookOutbox\Exceptions\InvalidWebhookConfigException;
use Zakobo\WebhookOutbox\Listeners\RecordWebhookOutboxOutcome;
use Zakobo\WebhookOutbox\Listeners\WebhookDeliveryLogger;
use Zakobo\WebhookOutbox\Models\WebhookOutboxMessage;
use Zakobo\WebhookOutbox\Tests\Fixtures\FieldAddingWebhookDeliveryLogger;
use Zakobo\WebhookOutbox\Tests\Fixtures\MetaOverridingRelayWebhookOutboxAction;
use Zakobo\WebhookOutbox\Tests\Fixtures\QueueingRelayWebhookOutboxAction;
use Zakobo\WebhookOutbox\Tests\TestCase;
use Zakobo\WebhookOutbox\ValueObjects\WebhookEvent;
use Zakobo\WebhookOutbox\WebhookOutboxServiceProvider;

final class SwappableClassesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        QueueingRelayWebhookOutboxAction::$customizedFor = [];
        config(['webhook-outbox.subscribers' => [
            'auth' => [
                'url' => 'https://auth.example.test/webhooks',
                'signing_secret' => 'auth-secret',
                'events' => '*',
            ],
        ]]);
    }

    #[Test]
    public function the_default_config_binds_the_original_classes_and_adds_nothing_to_the_call(): void
    {
        Bus::fake();

        $this->assertSame(RelayWebhookOutboxAction::class, app(RelayWebhookOutboxAction::class)::class);
        $this->assertSame(WebhookDeliveryLogger::class, app(WebhookDeliveryLogger::class)::class);

        $this->dispatchThroughTheFastPath();

        $webhookJob = Bus::dispatched(CallWebhookJob::class)->sole();
        $this->assertSame(config('webhook-server.queue'), $webhookJob->queue);
        $this->assertArrayNotHasKey('X-Custom', $webhookJob->headers);
    }

    #[Test]
    public function a_relay_subclass_customizes_the_call_on_the_fast_path(): void
    {
        Bus::fake();
        $this->useRelayAction(QueueingRelayWebhookOutboxAction::class);

        $this->dispatchThroughTheFastPath();

        $this->assertCustomizedCall(Bus::dispatched(CallWebhookJob::class)->sole());
        $this->assertSame('auth', QueueingRelayWebhookOutboxAction::$customizedFor[0][1]->name);
    }

    #[Test]
    public function a_relay_subclass_customizes_the_call_in_the_sweep(): void
    {
        Bus::fake();
        $this->useRelayAction(QueueingRelayWebhookOutboxAction::class);
        $outboxMessage = WebhookOutboxMessage::factory()->create(['subscriber' => 'auth', 'event' => 'thing.happened']);

        $enqueuedCount = app(RelayWebhookOutboxAction::class)->execute();

        $this->assertSame(1, $enqueuedCount);
        $this->assertCustomizedCall(Bus::dispatched(CallWebhookJob::class)->sole());
        $this->assertSame($outboxMessage->id, QueueingRelayWebhookOutboxAction::$customizedFor[0][0]->id);
    }

    #[Test]
    public function a_relay_subclass_customizes_the_call_on_replay(): void
    {
        Bus::fake();
        $this->useRelayAction(QueueingRelayWebhookOutboxAction::class);
        $outboxMessage = WebhookOutboxMessage::factory()->failed()->create([
            'subscriber' => 'auth',
            'event' => 'thing.happened',
        ]);

        app(ReplayWebhookOutboxMessageAction::class)->execute($outboxMessage);

        $this->assertCustomizedCall(Bus::dispatched(CallWebhookJob::class)->sole());
    }

    #[Test]
    public function the_package_meta_survives_a_subclass_that_sets_its_own_and_the_outcome_is_still_recorded(): void
    {
        Bus::fake();
        $this->useRelayAction(MetaOverridingRelayWebhookOutboxAction::class);
        $outboxMessage = WebhookOutboxMessage::factory()->create(['subscriber' => 'auth', 'event' => 'thing.happened']);

        app(RelayWebhookOutboxAction::class)->execute();

        $meta = Bus::dispatched(CallWebhookJob::class)->sole()->meta;
        $this->assertSame($outboxMessage->id, $meta['outbox_message_id']);
        $this->assertSame($outboxMessage->event_id, $meta['event_id']);
        $this->assertSame('auth', $meta['subscriber']);
        $this->assertArrayNotHasKey('own', $meta);

        app(RecordWebhookOutboxOutcome::class)->handleWebhookCallSucceededEvent(new WebhookCallSucceededEvent(
            'post',
            'https://auth.example.test/webhooks',
            [],
            [],
            $meta,
            [],
            1,
            new Response(204),
            null,
            null,
            'webhook-success',
            null,
        ));
        $this->assertSame(WebhookOutboxStatus::Succeeded, $outboxMessage->fresh()->status);
    }

    #[Test]
    public function a_logger_subclass_is_what_receives_the_spatie_events(): void
    {
        config(['webhook-outbox.classes.delivery_logger' => FieldAddingWebhookDeliveryLogger::class]);
        $logSpy = Log::spy();

        event(new WebhookCallSucceededEvent(
            'post',
            'https://auth.example.test/webhooks',
            [],
            [],
            ['event' => 'thing.happened', 'event_id' => 'e-1', 'subscriber' => 'auth'],
            [],
            1,
            new Response(204),
            null,
            null,
            'webhook-success',
            null,
        ));

        $logSpy->shouldHaveReceived('info')
            ->once()
            ->withArgs(fn (string $message, array $context): bool => $message === 'Webhook delivered.'
                && $context['added_by_subclass'] === true
                && $context['event_id'] === 'e-1');
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function invalidClassCases(): array
    {
        return [
            'relay action that is missing' => ['relay_action', 'App\\Missing\\Relay'],
            'relay action that does not extend the original' => ['relay_action', stdClass::class],
            'delivery logger that is missing' => ['delivery_logger', 'App\\Missing\\Logger'],
            'delivery logger that does not extend the original' => ['delivery_logger', stdClass::class],
        ];
    }

    #[Test]
    #[DataProvider('invalidClassCases')]
    public function booting_fails_for_a_class_that_is_missing_or_does_not_extend_the_original(
        string $configKey,
        string $configuredClass,
    ): void {
        config(["webhook-outbox.classes.{$configKey}" => $configuredClass]);

        $this->expectException(InvalidWebhookConfigException::class);
        $this->expectExceptionMessage("Config [webhook-outbox.classes.{$configKey}] must be");

        (new WebhookOutboxServiceProvider($this->app))->boot();
    }

    /**
     * @param  class-string<RelayWebhookOutboxAction>  $relayActionClass
     */
    private function useRelayAction(string $relayActionClass): void
    {
        config(['webhook-outbox.classes.relay_action' => $relayActionClass]);
    }

    private function dispatchThroughTheFastPath(): void
    {
        DB::transaction(fn () => app(DispatchWebhookAction::class)->execute(
            WebhookEvent::occurNow('thing.happened', ['id' => 'widget-1']),
        ));
    }

    private function assertCustomizedCall(CallWebhookJob $webhookJob): void
    {
        $this->assertSame('webhooks-priority', $webhookJob->queue);
        $this->assertSame(45, $webhookJob->requestTimeout);
        $this->assertSame('auth', $webhookJob->headers['X-Custom']);
        $this->assertSame('thing.happened', $webhookJob->headers['X-Zakobo-Webhook-Event']);
        $this->assertArrayHasKey('Signature', $webhookJob->headers);
    }
}
