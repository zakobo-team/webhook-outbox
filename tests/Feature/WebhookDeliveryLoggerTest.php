<?php

declare(strict_types=1);

namespace Zakobo\WebhookOutbox\Tests\Feature;

use Closure;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Spatie\WebhookServer\Events\DispatchingWebhookCallEvent;
use Spatie\WebhookServer\Events\FinalWebhookCallFailedEvent;
use Spatie\WebhookServer\Events\WebhookCallFailedEvent;
use Spatie\WebhookServer\Events\WebhookCallSucceededEvent;
use Zakobo\WebhookOutbox\Listeners\WebhookDeliveryLogger;
use Zakobo\WebhookOutbox\Tests\TestCase;

final class WebhookDeliveryLoggerTest extends TestCase
{
    private const array META = [
        'account_id' => 'acme',
        'event' => 'thing.happened',
        'event_id' => '09c829a2-a77b-422d-8562-2f88e784dfb5',
        'subscriber' => 'auth',
        'outbox_message_id' => 42,
    ];

    #[Test]
    public function listener_is_registered_for_every_spatie_webhook_event(): void
    {
        Event::fake();

        Event::assertListening(
            DispatchingWebhookCallEvent::class,
            [WebhookDeliveryLogger::class, 'handleDispatchingWebhookCallEvent'],
        );
        Event::assertListening(
            WebhookCallSucceededEvent::class,
            [WebhookDeliveryLogger::class, 'handleWebhookCallSucceededEvent'],
        );
        Event::assertListening(
            WebhookCallFailedEvent::class,
            [WebhookDeliveryLogger::class, 'handleWebhookCallFailedEvent'],
        );
        Event::assertListening(
            FinalWebhookCallFailedEvent::class,
            [WebhookDeliveryLogger::class, 'handleFinalWebhookCallFailedEvent'],
        );
    }

    #[Test]
    public function a_call_without_our_meta_keys_is_ignored(): void
    {
        $logSpy = Log::spy();

        app(WebhookDeliveryLogger::class)->handleDispatchingWebhookCallEvent(new DispatchingWebhookCallEvent(
            'post',
            'https://other.example.test/webhooks',
            [],
            [],
            ['event' => 'other.updated'],
            [],
            'webhook-ignored',
        ));

        $logSpy->shouldNotHaveReceived('info');
        $logSpy->shouldNotHaveReceived('warning');
        $logSpy->shouldNotHaveReceived('error');
        $logSpy->shouldNotHaveReceived('critical');
    }

    /**
     * @return array<string, array{string, Closure, string, string, Closure}>
     */
    public static function webhookCallEventCases(): array
    {
        return [
            'dispatching' => [
                'handleDispatchingWebhookCallEvent',
                fn (): DispatchingWebhookCallEvent => new DispatchingWebhookCallEvent(
                    'post',
                    'https://auth.example.test/webhooks/thing-updates',
                    [],
                    [],
                    self::META,
                    [],
                    'webhook-dispatching',
                ),
                'info',
                'Webhook dispatching.',
                fn (array $context): bool => $context['subscriber'] === 'auth'
                    && $context['event'] === 'thing.happened'
                    && $context['event_id'] === '09c829a2-a77b-422d-8562-2f88e784dfb5'
                    && $context['account_id'] === 'acme'
                    && $context['outbox_message_id'] === 42
                    && $context['webhook_uuid'] === 'webhook-dispatching'
                    && ! array_key_exists('payload', $context),
            ],
            'succeeded' => [
                'handleWebhookCallSucceededEvent',
                fn (): WebhookCallSucceededEvent => new WebhookCallSucceededEvent(
                    'post',
                    'https://auth.example.test/webhooks/thing-updates',
                    [],
                    [],
                    self::META,
                    [],
                    2,
                    new Response(204),
                    null,
                    null,
                    'webhook-success',
                    null,
                ),
                'info',
                'Webhook delivered.',
                fn (array $context): bool => $context['status_code'] === 204 && $context['attempt'] === 2,
            ],
            'failed' => [
                'handleWebhookCallFailedEvent',
                fn (): WebhookCallFailedEvent => new WebhookCallFailedEvent(
                    'post',
                    'https://auth.example.test/webhooks/thing-updates',
                    [],
                    [],
                    self::META,
                    [],
                    3,
                    new Response(503),
                    'server_error',
                    'Service unavailable',
                    'webhook-failed',
                    null,
                ),
                'warning',
                'Webhook delivery failed.',
                fn (array $context): bool => $context['exception'] === 'server_error'
                    && $context['exception_message'] === 'Service unavailable',
            ],
            'final failed' => [
                'handleFinalWebhookCallFailedEvent',
                fn (): FinalWebhookCallFailedEvent => new FinalWebhookCallFailedEvent(
                    'post',
                    'https://auth.example.test/webhooks/thing-updates',
                    [],
                    [],
                    self::META,
                    [],
                    4,
                    null,
                    'timeout',
                    'Request timed out',
                    'webhook-final-failed',
                    null,
                ),
                'critical',
                'Webhook final delivery failed.',
                fn (array $context): bool => $context['subscriber'] === 'auth'
                    && $context['event'] === 'thing.happened'
                    && $context['event_id'] === '09c829a2-a77b-422d-8562-2f88e784dfb5'
                    && $context['account_id'] === 'acme'
                    && $context['outbox_message_id'] === 42
                    && $context['exception'] === 'timeout'
                    && $context['exception_message'] === 'Request timed out'
                    && ! array_key_exists('payload', $context),
            ],
        ];
    }

    #[Test]
    #[DataProvider('webhookCallEventCases')]
    public function a_webhook_call_event_with_our_meta_keys_is_logged_at_the_expected_level(
        string $handlerMethod,
        Closure $eventFactory,
        string $expectedLevel,
        string $expectedMessage,
        Closure $additionalAssertions,
    ): void {
        $logSpy = Log::spy();

        app(WebhookDeliveryLogger::class)->{$handlerMethod}($eventFactory());

        $logSpy->shouldHaveReceived($expectedLevel)
            ->once()
            ->withArgs(fn (string $message, array $context): bool => $message === $expectedMessage
                && $additionalAssertions($context));
    }
}
