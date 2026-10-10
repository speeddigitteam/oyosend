<?php

declare(strict_types=1);

namespace App\Modules\CRM\Enums;

enum QuoteStatus: string
{
    case Draft = 'draft';
    case Sent = 'sent';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
    case Expired = 'expired';
    case Canceled = 'canceled';
}
