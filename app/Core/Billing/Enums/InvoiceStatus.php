<?php

declare(strict_types=1);

namespace App\Core\Billing\Enums;

enum InvoiceStatus: string
{
    /** Issued and waiting for payment. */
    case Open = 'open';

    case Paid = 'paid';

    /** Withdrawn: replaced by another invoice or no longer owed. */
    case Void = 'void';
}
