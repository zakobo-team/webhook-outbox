## Webhook Outbox

This application sends webhooks with `zakobo/webhook-outbox`, a transactional outbox. The package writes each
webhook to the `webhook_outbox` table in the transaction of the change that caused it. Delivery is at-least-once.

- Activate the `webhook-outbox-development` skill before you send, configure, extend, test or debug a webhook, and
  before you write an endpoint that receives one.
- Send a webhook only with `Zakobo\WebhookOutbox\Actions\DispatchWebhookAction`. Do not call
  `Spatie\WebhookServer\WebhookCall` directly.
- Send it in a transaction on the outbox connection (`WebhookOutboxMessage::outboxConnection()->transaction(...)`).
- Lock the entity with `lockForUpdate()` first. Build the `WebhookEvent` after you have the lock.
- Subscribers are in `config/webhook-outbox.php`. An incorrect entry stops the boot of the application.
- Do not add `webhooks:relay` or the prune of `WebhookOutboxMessage` to the schedule. The package schedules them.
- Do not change the `status` or the `enqueued_at` of an outbox row manually. Use `php artisan webhooks:replay`.
