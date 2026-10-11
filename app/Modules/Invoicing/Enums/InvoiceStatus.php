<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Enums;

enum InvoiceStatus: string
{
    case Draft = 'draft';
    case Issued = 'issued';
    case PartiallyPaid = 'partially_paid';
    case Paid = 'paid';
    case Overdue = 'overdue';
    case Void = 'void';

    /** Issued and still owing money (an overdue invoice is open too). */
    public function isOpen(): bool
    {
        return in_array($this, [self::Issued, self::PartiallyPaid, self::Overdue], true);
    }
}
