<?php

declare(strict_types=1);

return [
    /*
     * Database connection holding the outbox table. Null uses the application's default connection. Every
     * sender must run its business transaction on this connection so the outbox rows commit or roll back with it.
     */
    'connection' => env('OUTBOX_DB_CONNECTION'),

    'table' => 'webhook_outbox',

    /*
     * Delivery headers are named `{header_prefix}-Event` and `{header_prefix}-Subscriber`.
     */
    'header_prefix' => 'X-Zakobo-Webhook',

    /*
     * Outbox message bodies can carry personal data, so rows are pruned after this many days.
     */
    'prune_after_days' => 90,

    /*
     * Each subscriber declares which webhook events it receives via `events`: either the string '*' (every
     * event) or a list of event names. A subscriber with an empty `url` is skipped. Shape:
     *
     * 'subscribers' => [
     *     'auth' => [
     *         'url' => env('WEBHOOK_AUTH_URL'),
     *         'signing_secret' => env('WEBHOOK_AUTH_SIGNING_SECRET'),
     *         'events' => '*',
     *     ],
     * ],
     */
    'subscribers' => [],
];
