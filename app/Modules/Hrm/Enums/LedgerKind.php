<?php

declare(strict_types=1);

namespace App\Modules\Hrm\Enums;

enum LedgerKind: string
{
    case Accrual = 'accrual';
    case CarryForward = 'carry_forward';
    case CarryOut = 'carry_out';
    case Adjustment = 'adjustment';
    case Request = 'request';
    case Reversal = 'reversal';
}
