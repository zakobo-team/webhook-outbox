# Webhook Outbox for Laravel

A transactional outbox for webhooks in Laravel. A webhook is written to a database table in the same transaction as the
change that triggered it, handed to the queue after commit, and swept up by a scheduled command if that hand-off was
missed. Delivery, retries and signing are done by
[spatie/laravel-webhook-server](https://github.com/spatie/laravel-webhook-server).

- The outbox row is as durable as the change itself: it commits or rolls back with your transaction.
- Delivery is **at-least-once**. A crash between commit and queue dispatch is retried by `webhooks:relay`.
- Every outbox row records its status (`pending`, `succeeded`, `failed`), attempt count, last status code and last
  error, and can be replayed byte-for-byte.

## Installation

The package is not on Packagist. Require it from the Git repository:

```json
{
    "repositories": [
        {
            "type": "vcs",
            "url": "https://github.com/zakobo-team/webhook-outbox"
        }
    ],
    "require": {
        "zakobo/webhook-outbox": "0.1.0"
    }
}
```

The service provider is auto-discovered. It merges the config, registers the `webhooks:relay` and `webhooks:replay`
commands, listens to spatie's webhook events to record outcomes and write delivery logs, and schedules the daily
prune (see [Commands and schedule](#commands-and-schedule)).

Publish the config and the migration, then migrate:

```bash
php artisan vendor:publish --tag=webhook-outbox-config
php artisan vendor:publish --tag=webhook-outbox-migrations
php artisan migrate
```

Then schedule the relay in your application, for example in `routes/console.php`. The package does not do this for you:

```php
Schedule::command('webhooks:relay')->everyMinute()->withoutOverlapping();
```

An application that already has the outbox table (for example one that predates this package) must **not** publish the
migration. The package never loads its migration on its own.

## Configuration

`config/webhook-outbox.php`:

| Key                | Default                          | Meaning                                                                           |
|--------------------|----------------------------------|-----------------------------------------------------------------------------------|
| `connection`       | `env('WEBHOOK_OUTBOX_DB_CONNECTION')` (`null`) | Connection holding the outbox table. `null` is the application's default connection. |
| `table`            | `webhook_outbox`                 | Outbox table name.                                                                |
| `header_prefix`    | `X-Zakobo-Webhook`               | Delivery headers are `{prefix}-Event` and `{prefix}-Subscriber`.                  |
| `prune_after_days` | `90`                             | Rows older than this are pruned (bodies can carry personal data).                 |
| `subscribers`      | `[]`                             | Receivers, keyed by name.                                                         |
| `classes.relay_action` | `RelayWebhookOutboxAction`   | Class that builds and dispatches each webhook call. A replacement must extend it. |
| `classes.delivery_logger` | `WebhookDeliveryLogger`   | Class that logs Spatie's webhook events. A replacement must extend it.            |

Each subscriber declares its `url`, its `signing_secret` and the `events` it receives: `'*'` for every event, or a list
of event names. A subscriber with an empty `url` is skipped, so a receiver can be switched off per environment. A URL
without a signing secret, or a malformed entry, throws `InvalidWebhookConfigException`. The config is validated once,
eagerly, when the application boots, so a bad entry fails the deploy instead of the first request or job that happens
to send a webhook.

```php
'subscribers' => [
    'auth' => [
        'url' => env('WEBHOOK_AUTH_URL'),
        'signing_secret' => env('WEBHOOK_AUTH_SIGNING_SECRET'),
        'events' => '*',
    ],
    'accounting' => [
        'url' => env('WEBHOOK_ACCOUNTING_URL'),
        'signing_secret' => env('WEBHOOK_ACCOUNTING_SIGNING_SECRET'),
        'events' => ['invoice.created', 'invoice.updated'],
    ],
],
```

## Dispatching a webhook

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

`$dispatchWebhook` is an injected `DispatchWebhookAction`. `WebhookEvent::occurNow($name, $payload, $logContext = [])`
generates the `event_id` (a UUID). The request body is `event`, `event_id`, `occurred_at`, then your payload keys, in
that order; `event`, `event_id` and `occurred_at` are reserved payload keys. `logContext` is added to delivery logs and
is never sent to the receiver.

`execute()` writes one outbox row per subscribed subscriber, then enqueues them after the surrounding transaction
commits. If that transaction rolls back, no row survives and nothing is enqueued. Called outside a transaction it
commits its own rows and enqueues straight away. A failure while enqueuing is reported and never thrown to the caller:
the row is already durable and the sweep will pick it up.

### The sender rule

**Dispatch inside a database transaction on the outbox connection, and lock the entity first** (`lockForUpdate()`) so
concurrent writers cannot race inconsistent snapshots into the outbox. If two requests change the same entity at once
and each builds its payload from what it read before the other committed, the receiver can end up with a stale
snapshot that looks current. Locking the entity serialises the writers, so each outbox row is built from the state the
previous writer committed.

## Delivery guarantees

- **At-least-once.** A row is marked enqueued only after its job has been dispatched, and `webhooks:relay` retries every
  row that is not. A row is never enqueued twice by concurrent sweeps: each is locked with `for update skip locked`
  and re-checked inside its own transaction.
- **No ordering guarantee.** Events for the same entity can arrive out of order. There is no per-event sequence
  number, so receivers should treat events as upserts where the last one applied wins; a stale snapshot is corrected
  by the entity's next change.
- **The relay retries without a cap.** A row whose enqueue keeps failing is retried on every sweep, forever. Once a job
  is queued, spatie's own retry and backoff apply, and a message that exhausts them is marked `failed` and logged at
  `critical`.
- A row whose subscriber is no longer configured (or no longer subscribes to the event) is dead-lettered: marked
  `failed` and enqueued, so it is not retried.

## Commands and schedule

| Command                                | Purpose                                                                                     |
|----------------------------------------|---------------------------------------------------------------------------------------------|
| `php artisan webhooks:relay`           | Relays every outbox row the fast path has not yet enqueued.                                 |
| `php artisan webhooks:replay {ids*}`   | Resends the given rows verbatim (same `event_id` and body) to the subscriber's current URL. |
| `php artisan webhooks:replay --failed` | Resends every `failed` row. `pending` rows must be replayed explicitly by id.               |

`webhooks:replay` needs either ids or `--failed`, not both, and ids must be positive integers of existing rows.

**The application must schedule the relay itself.** The package does not:

```php
// routes/console.php
Schedule::command('webhooks:relay')->everyMinute()->withoutOverlapping();
```

Without it, a row the fast path missed (for example after a crash between commit and queue dispatch) is never
retried.

The package does schedule the prune: `model:prune` for `Zakobo\WebhookOutbox\Models\WebhookOutboxMessage` daily,
without overlapping, deleting rows older than `prune_after_days`.

The scheduler (`schedule:work` or a `schedule:run` cron entry) and a queue worker must be running.

## Extending

Like spatie/laravel-webhook-server's `signer` and `webhook_job` keys, `config/webhook-outbox.php` has a `classes` array
naming the classes the package resolves from the container:

```php
'classes' => [
    'relay_action' => RelayWebhookOutboxAction::class,
    'delivery_logger' => WebhookDeliveryLogger::class,
],
```

A replacement must extend the listed class. Anything else, including a class that does not exist, fails the application
boot with an `InvalidWebhookConfigException`. Protected methods are the extension API; everything private is not.

### The webhook call

Override `webhookCallFor()` to change how each call is dispatched: queue, timeout, tries, backoff, proxy or mTLS, extra
headers, tags, HTTP verb. Call the parent first and keep customizing the returned Spatie `WebhookCall`:

```php
use Spatie\WebhookServer\WebhookCall;
use Zakobo\WebhookOutbox\Actions\RelayWebhookOutboxAction;
use Zakobo\WebhookOutbox\Models\WebhookOutboxMessage;
use Zakobo\WebhookOutbox\ValueObjects\Subscriber;

class AppRelayWebhookOutboxAction extends RelayWebhookOutboxAction
{
    protected function webhookCallFor(WebhookOutboxMessage $outboxMessage, Subscriber $subscriber): WebhookCall
    {
        $webhookCall = parent::webhookCallFor($outboxMessage, $subscriber);

        if ($subscriber->name === 'accounting') {
            return $webhookCall->onQueue('webhooks-slow')->timeoutInSeconds(60);
        }

        return $webhookCall;
    }
}
```

The fast path after commit, the `webhooks:relay` sweep and `webhooks:replay` all go through the relay action, so an
override applies to all three.

**An override must not change the payload or the signing.** A replay resends the stored body byte-for-byte, and
receivers verify the `Signature` header against it. The package also applies the call's meta itself, after
`webhookCallFor()` returns, so the outcome recorder and the delivery logger always get their `outbox_message_id`,
`event_id` and `subscriber` even if an override sets its own meta.

`execute()` is `final`; locking, row selection, dead-lettering and marking rows enqueued stay private to the package.

### The delivery logger

Override `log()` to change the channel, `context()` to change the fields, or any of the four `handle…` methods:

```php
use Illuminate\Support\Facades\Log;
use Zakobo\WebhookOutbox\Listeners\WebhookDeliveryLogger;

class AppWebhookDeliveryLogger extends WebhookDeliveryLogger
{
    protected function log(string $level, string $message, array $context): void
    {
        Log::channel('webhooks')->{$level}($message, $context);
    }
}
```

The listeners are registered against the configured class, so the replacement receives Spatie's events. Events from
webhook calls that did not come from the outbox never reach `log()`: the package filters them out first.

## Receiver contract

A receiver of these webhooks must:

- **Verify the `Signature` header.** It is the HMAC-SHA256 of the raw request body, keyed with the subscriber's
  signing secret. Compare with `hash_equals` on the raw bytes, before decoding the JSON. `Timestamp` is informational
  only.
- **Be idempotent on `event_id`.** Delivery is at-least-once, and a replay resends the same `event_id`.
- **Tolerate out-of-order delivery** (see [Delivery guarantees](#delivery-guarantees)).
- Respond with a 2xx status once the event is safely stored.

## Testing

The suite runs against MySQL, since `skip locked` and MySQL's changed-rows semantics cannot be tested on SQLite. It
needs a database, by default `webhook_outbox_testing` on `127.0.0.1` as `root` without a password, overridable
with `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME` and `DB_PASSWORD`:

```bash
composer test
composer analyse
composer lint:check
```

In your own application's tests, `WebhookOutboxMessage::factory()` is available.
