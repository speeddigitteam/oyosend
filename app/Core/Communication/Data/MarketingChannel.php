<?php

declare(strict_types=1);

namespace App\Core\Communication\Data;

/** An outbound channel a marketing app can use through the communication platform. */
enum MarketingChannel: string
{
    case Email = 'email';
    case Sms = 'sms';
    case Whatsapp = 'whatsapp';
}
