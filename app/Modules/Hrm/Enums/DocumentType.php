<?php

declare(strict_types=1);

namespace App\Modules\Hrm\Enums;

enum DocumentType: string
{
    case Contract = 'contract';
    case Identity = 'identity';
    case Certificate = 'certificate';
    case Offer = 'offer';
    case Other = 'other';
}
