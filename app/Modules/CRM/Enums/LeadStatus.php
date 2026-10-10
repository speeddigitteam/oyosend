<?php

declare(strict_types=1);

namespace App\Modules\CRM\Enums;

enum LeadStatus: string
{
    case New = 'new';
    case Contacted = 'contacted';
    case Qualified = 'qualified';
    case Disqualified = 'disqualified';
    case Converted = 'converted';
}
