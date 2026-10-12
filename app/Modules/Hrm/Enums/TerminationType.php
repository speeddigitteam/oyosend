<?php

declare(strict_types=1);

namespace App\Modules\Hrm\Enums;

enum TerminationType: string
{
    case Resignation = 'resignation';
    case Dismissal = 'dismissal';
    case EndOfContract = 'end_of_contract';
    case Retirement = 'retirement';
    case Redundancy = 'redundancy';
    case Other = 'other';
}
