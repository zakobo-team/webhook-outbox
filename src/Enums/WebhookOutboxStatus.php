<?php

declare(strict_types=1);

namespace Zakobo\WebhookOutbox\Enums;

enum WebhookOutboxStatus: string
{
    case Pending = 'pending';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
}
