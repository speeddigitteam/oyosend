<?php

declare(strict_types=1);

namespace App\Modules\CRM\Enums;

enum QuoteKind: string
{
    case Quotation = 'quotation';
    case Proposal = 'proposal';
}
