<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Enums;

/** How money was received outside the application. No gateway is involved. */
enum PaymentMethod: string
{
    case Cash = 'cash';
    case BankTransfer = 'bank_transfer';
    case MobileBanking = 'mobile_banking';
    case Cheque = 'cheque';
    case Card = 'card';
    case Other = 'other';
}
