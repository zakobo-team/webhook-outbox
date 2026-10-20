---
name: webhook-outbox-development
description: Use when you send, configure, extend, test or debug webhooks with zakobo/webhook-outbox. Covers DispatchWebhookAction, WebhookEvent, the sender rule (transaction and entity lock), config/webhook-outbox.php subscribers, the webhook_outbox table, the webhooks:relay and webhooks:replay commands, and the receiver contract.
---

# Webhook Outbox Development

`zakobo/webhook-outbox` is a transactional outbox for webhooks. It writes each webhook to a database table in the
transaction of the change that caused it. `spatie/laravel-webhook-server` does the delivery, the retries and the
signing.

## When to use this skill

- You send a webhook from application code.
- You add or change a subscriber (a receiver).
- You change the queue, the timeout, the retries, the headers or the logs of the delivery.
- You write a test for code that sends a webhook.
- You find the cause of a webhook that did not arrive, or you send one again.
- You write the endpoint that receives these webhooks.

## How it works

1. `DispatchWebhookAction::execute()` writes one `webhook_outbox` row for each subscriber of the event.
2. After the transaction commits, the relay action puts a `CallWebhookJob` on the queue and sets `enqueued_at`.
3. A queue worker sends the request. A listener records the result on the row.
4. `webhooks:relay` runs each minute. It puts each row without `enqueued_at` on the queue.

If the transaction rolls back, no row stays and nothing is sent. Delivery is at-least-once. There is no guarantee
of the delivery sequence.

A row has the status `pending`, `succeeded` or `failed` (`WebhookOutboxStatus`). It also has `attempts`,
`last_status_code`, `last_error`, `enqueued_at` and `delivered_at`.

## Send a webhook

Inject `DispatchWebhookAction`. Do not call `WebhookCall::create()` from spatie directly. A direct call has no
outbox row and is lost if the transaction rolls back.

```php
use Zakobo\WebhookOutbox\Actions\DispatchWebhookAction;
use Zakobo\WebhookOutbox\Models\WebhookOutboxMessage;
use Zakobo\WebhookOutbox\ValueObjects\WebhookEvent;

WebhookOutboxMessage::outboxConnection()->transaction(function () use ($invoiceId, $dispatchWebhook) {
    $invoice = Invoice::query()->lockForUpdate()->findOrFail($invoiceId);
    $invoice->update([...]);

    $dispatchWebhook->execute(WebhookEvent::occurNow('invoice.updated', [
        'id' => $invoice->id,
        'total' => $invoice->total,
    ]));
});
```

### The sender rule

Obey these rules each time you send a webhook:

1. Open the transaction on the outbox connection: `WebhookOutboxMessage::outboxConnection()->transaction(...)`.
   `DB::transaction()` is correct only when `webhook-outbox.connection` is `null` or the default connection.
2. Lock the entity with `lockForUpdate()` before you change it.
3. Build the `WebhookEvent` after you have the lock. `occurNow()` sets `occurred_at` at that moment, and receivers
   use `occurred_at` to put events in sequence.
4. Call `execute()` in the transaction.
5. Put the full state of the entity in the payload. The receiver rule "apply only the newer event" needs the full
   state.

### WebhookEvent

- `WebhookEvent::occurNow(string $name, array $payload, array $logContext = [])` makes the `event_id` (a UUID) and
  the `occurred_at` time.
- The request body is `event`, `event_id`, `occurred_at`, then the payload keys.
- Do not use `event`, `event_id` or `occurred_at` as payload keys. The constructor throws an
  `InvalidArgumentException`.
- `logContext` goes into the delivery logs only. The receiver does not get it. Its values must be scalar or `null`.
- Name events as `entity.action`, for example `invoice.updated`. Look at the existing event names in the
  application first.

### What `execute()` does

- It returns without a row when no subscriber subscribes to the event.
- Outside a transaction, it commits its rows and puts them on the queue immediately.
- It does not throw when the queue is not available. It reports the error, and `webhooks:relay` sends the row
  later.

## Subscribers

Subscribers are in `config/webhook-outbox.php`, with their name as the key:

```php
'subscribers' => [
    'accounting' => [
        'url' => env('WEBHOOK_ACCOUNTING_URL'),
        'signing_secret' => env('WEBHOOK_ACCOUNTING_SIGNING_SECRET'),
        'events' => ['invoice.created', 'invoice.updated'], // or '*' for each event
    ],
],
```

- An empty `url` disables the subscriber. Use this to disable a receiver in one environment.
- A `url` without a `signing_secret`, or an incorrect `events` value, throws `InvalidWebhookConfigException`.
- The package validates the config when the application boots. An incorrect entry stops each request and each
  command.
- When you add an event name, add it to the `events` list of each subscriber that must get it.
- Add the new `env` keys to `.env.example`.

Other config keys: `connection`, `table`, `header_prefix`, `prune_after_days`, `stuck_after_minutes` and `classes`.
Keep `stuck_after_minutes` more than the longest wait between retries (1 hour by default).

## Extend the package

Two classes are replaceable through `webhook-outbox.classes`. A replacement must extend the original class, or the
application does not boot. Only the protected methods are the extension API.

| Config key        | Original class             | Override                                                   |
|-------------------|----------------------------|------------------------------------------------------------|
| `relay_action`    | `RelayWebhookOutboxAction` | `webhookCallFor()`                                         |
| `delivery_logger` | `WebhookDeliveryLogger`    | `log()`, `context()`, or one of the four `handle…` methods |

```php
class AppRelayWebhookOutboxAction extends RelayWebhookOutboxAction
{
    protected function webhookCallFor(WebhookOutboxMessage $outboxMessage, Subscriber $subscriber): WebhookCall
    {
        $webhookCall = parent::webhookCallFor($outboxMessage, $subscriber);

        return $subscriber->name === 'accounting'
            ? $webhookCall->onQueue('webhooks-slow')->timeoutInSeconds(60)
            : $webhookCall;
    }
}
```

- Call `parent::webhookCallFor()` first. Then change the returned spatie `WebhookCall`.
- Use it for the queue, the timeout, the number of tries, the backoff, a proxy, more headers or tags.
- Do not change the payload or the signing. A replay sends the stored body again, byte for byte, and the receiver
  verifies the `Signature` header against it.
- Do not rely on `meta()`. The package sets the meta after `webhookCallFor()` returns.
- If you change the backoff, keep its longest wait less than `stuck_after_minutes`.
- `execute()` is `final`. Do not copy or replace the relay logic.

## Commands and schedule

| Command                                | Function                                                                     |
|----------------------------------------|------------------------------------------------------------------------------|
| `php artisan webhooks:relay`           | Puts each row without `enqueued_at` on the queue. Marks stuck rows `failed`. |
| `php artisan webhooks:replay {ids*}`   | Sends the given rows again with the same `event_id` and body.                |
| `php artisan webhooks:replay --failed` | Sends each `failed` row again.                                               |

- The package schedules `webhooks:relay` each minute and `model:prune` for `WebhookOutboxMessage` each day.
- Do not add these two commands to the schedule of the application.
- The scheduler and a queue worker must run.
- `webhooks:replay` accepts ids or `--failed`, not the two together. A replay sends to the current URL of the
  subscriber.

## Find the cause of a missing webhook

Query `WebhookOutboxMessage` by `event`, `subscriber` or `event_id`. Then read the row:

| Row state                                           | Cause and action                                                                           |
|-----------------------------------------------------|--------------------------------------------------------------------------------------------|
| No row                                              | The transaction rolled back, or no subscriber subscribes to the event. Examine the config. |
| `pending`, `enqueued_at` is `null`                  | The row is not on the queue. Make sure that the scheduler runs `webhooks:relay`.           |
| `pending`, `enqueued_at` is set                     | The job is on the queue or between retries. Make sure that a queue worker runs.            |
| `failed`, `last_error` has "subscriber not configured" | The subscriber is gone or does not subscribe to the event. Correct the config, then replay. |
| `failed`, `last_error` has "never recorded"         | The queue lost the job or the worker stopped. Replay the row.                              |
| `failed`, other                                     | The retries are used up (24 tries in approximately 20 hours). Read `last_status_code`.     |

The delivery logs have the `event_id`, the `subscriber` and the `logContext` of the event.

Do not change the `status` or the `enqueued_at` of a row manually. Use `webhooks:replay`.

## Tests in the application

- Use `Bus::fake()` to stop the delivery. `Http::fake()` does not stop it, because spatie uses Guzzle directly.
- Set the subscribers in the test: `config(['webhook-outbox.subscribers' => [...]])`.
- Make sure that the row exists and that the job is on the queue:

```php
Bus::fake();
config(['webhook-outbox.subscribers' => [
    'accounting' => ['url' => 'https://accounting.test/hook', 'signing_secret' => 'secret', 'events' => '*'],
]]);

$this->updateInvoice($invoice);

$outboxMessage = WebhookOutboxMessage::query()->where('event', 'invoice.updated')->sole();
$this->assertSame($invoice->id, $outboxMessage->payload['id']);
Bus::assertDispatched(CallWebhookJob::class);
```

- `WebhookOutboxMessage::factory()` makes rows. Its states are `succeeded()`, `failed()` and `enqueued()`.
- The relay uses `for update skip locked`. Run tests of the relay against MySQL, not SQLite.

## The receiver contract

Apply these rules when you write an endpoint that receives these webhooks:

- Verify the `Signature` header. It is the HMAC-SHA256 of the raw request body, with the signing secret as the
  key. Compare with `hash_equals` before you decode the JSON.
- Be idempotent on `event_id`. The same event can arrive more than one time.
- Apply an event only if its `occurred_at` is later than the stored one for that entity. Store `occurred_at` with
  microseconds (`DATETIME(6)` in MySQL).
- Keep a tombstone for a deleted entity, with its `occurred_at`. Then a late update does not make the entity
  again.
- Return a 2xx status after the event is stored.

## Common mistakes

- You send a webhook outside a transaction. Then the change and the webhook can disagree.
- You build the `WebhookEvent` before the lock. Then `occurred_at` can be in the incorrect sequence.
- You use `DB::transaction()` when the outbox is on a different connection. Then the row does not roll back with
  the change.
- You publish the migration in an application that already has the `webhook_outbox` table.
- You expect the events to arrive in sequence. Use `occurred_at` on the receiver.
- You send a partial payload. Then the receiver can drop an older event that has other fields.
