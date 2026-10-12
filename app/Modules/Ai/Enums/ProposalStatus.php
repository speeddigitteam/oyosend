<?php

declare(strict_types=1);

namespace App\Modules\Ai\Enums;

enum ProposalStatus: string
{
    case Pending = 'pending';
    case Confirmed = 'confirmed';
    case Rejected = 'rejected';
    case Failed = 'failed';
    case Expired = 'expired';
}
