<?php

declare(strict_types=1);

namespace Zakobo\Outbox\Enums;

enum WebhookOutboxStatus: string
{
    case Pending = 'pending';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
}
