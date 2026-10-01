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
commands, listens to spatie's webhook events to record outcomes and write delivery logs, and schedules the relay and
the daily prune (see [Commands and schedule](#commands-and-schedule)).

Publish the config and the migration, then migrate:

```bash
php artisan vendor:publish --tag=webhook-outbox-config
php artisan vendor:publish --tag=webhook-outbox-migrations
php artisan migrate
```

An application that already has the outbox table (for example one that predates this package) must **not** publish the
migration. The package never loads its migration on its own. Such a table should get an index on
`(status, updated_at)`, which the stuck-row check in `webhooks:relay` queries every minute. It covers what a
single-column `status` index did, so that one can go:

```php
Schema::table('webhook_outbox', function (Blueprint $table): void {
    $table->index(['status', 'updated_at']);
    $table->dropIndex(['status']);
});
```

## Configuration

`config/webhook-outbox.php`:

| Key                | Default                          | Meaning                                                                           |
|--------------------|----------------------------------|-----------------------------------------------------------------------------------|
| `connection`       | `env('WEBHOOK_OUTBOX_DB_CONNECTION')` (`null`) | Connection holding the outbox table. `null` is the application's default connection. |
| `table`            | `webhook_outbox`                 | Outbox table name.                                                                |
| `header_prefix`    | `X-Zakobo-Webhook`               | Delivery headers are `{prefix}-Event` and `{prefix}-Subscriber`.                  |
| `prune_after_days` | `90`                             | Rows older than this are pruned (bodies can carry personal data).                 |
| `stuck_after_minutes` | `120`                         | An enqueued row still `pending` with no write for this long is marked `failed`. Must exceed the longest backoff wait (1 hour). |
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

**Build the `WebhookEvent` after taking the lock**, as in the example above. `occurNow()` stamps `occurred_at` (with
microseconds) at that moment, and receivers order events for the same entity by it. Built after the lock, a later
change always carries a later `occurred_at`.

## Delivery guarantees

- **At-least-once.** A row is marked enqueued only after its job has been dispatched, and `webhooks:relay` retries every
  row that is not. A row is never enqueued twice by concurrent sweeps: each is locked with `for update skip locked`
  and re-checked inside its own transaction.
- **No delivery-order guarantee, but events are orderable.** Events for the same entity can arrive out of order: a
  retry after backoff, parallel queue workers or a `webhooks:replay` can deliver an older event after a newer one.
  `occurred_at` (ISO 8601 with microseconds) increases per entity when senders follow the sender rule, so receivers
  apply an event only when it is newer than what they stored (see [Receiver contract](#receiver-contract)).
- **Retries for about 20 hours.** Each delivery is tried up to 24 times, waiting 10s, 1m, 5m, 30m, then hourly. A
  message that exhausts them is marked `failed` and logged at `critical`. An override of `webhookCallFor()` can change
  this with spatie's `maximumTries()` and `useBackoffStrategy()`; keep the longest wait below `stuck_after_minutes`.
- **Lost outcomes are failed, not forgotten.** If the queue loses a job, or the worker dies or hits an `Error` on the
  last attempt, spatie records no outcome. `webhooks:relay` marks every enqueued row that has stayed `pending` without
  a write for `stuck_after_minutes` as `failed` and logs its id at `critical`, so `webhooks:replay --failed` resends it.
  A delivery that still lands afterwards marks it `succeeded`.
- **The relay retries enqueueing without a cap.** A row whose enqueue keeps failing is retried on every sweep, forever,
  and logged at `error` each time.
- A row whose subscriber is no longer configured (or no longer subscribes to the event) is dead-lettered: marked
  `failed` and enqueued, so it is not retried.

## Commands and schedule

| Command                                | Purpose                                                                                     |
|----------------------------------------|---------------------------------------------------------------------------------------------|
| `php artisan webhooks:relay`           | Relays every outbox row the fast path has not yet enqueued, and marks stuck rows `failed`.  |
| `php artisan webhooks:replay {ids*}`   | Resends the given rows verbatim (same `event_id` and body) to the subscriber's current URL. |
| `php artisan webhooks:replay --failed` | Resends every `failed` row. `pending` rows must be replayed explicitly by id.               |

`webhooks:replay` needs either ids or `--failed`, not both, and ids must be positive integers of existing rows.

The package schedules both commands itself:

- `webhooks:relay` every minute, without overlapping. An application that scheduled it before this package did should
  remove its own entry.
- `model:prune` for `Zakobo\WebhookOutbox\Models\WebhookOutboxMessage` daily, without overlapping, deleting rows older
  than `prune_after_days`.

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
- **Apply an event only if it is newer than what it stored.** Keep the last applied `occurred_at` per entity, in a
  column with microsecond precision (`DATETIME(6)` in MySQL; a plain `DATETIME` truncates to seconds and lets events
  in the same second collide). Create the entity when it is missing, otherwise update it only when the event's
  `occurred_at` is later. Bind the parsed timestamp (UTC, with microseconds), not the raw string:

  ```sql
  -- creates the entity when it is missing; a no-op otherwise
  INSERT IGNORE INTO users (id, ..., webhook_occurred_at) VALUES (:id, ..., :occurred_at);
  -- applies the event only when it is newer; a no-op for the row just inserted
  UPDATE users SET ..., webhook_occurred_at = :occurred_at
  WHERE id = :id AND (webhook_occurred_at IS NULL OR webhook_occurred_at < :occurred_at);
  ```

  This rule assumes every event for the entity carries its full state. For events that carry only part of it
  (`user.role_changed` next to `user.email_changed`), keep one `occurred_at` per event type instead, or an older role
  change is skipped once a newer email change has been applied.

- **Keep a tombstone for deleted entities.** Handle a delete event as a soft delete that also stores its
  `occurred_at`, so a late update for the deleted entity is skipped instead of bringing it back. With one
  `occurred_at` per event type, also skip every event older than the delete.
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
