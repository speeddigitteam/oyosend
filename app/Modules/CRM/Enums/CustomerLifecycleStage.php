<?php

declare(strict_types=1);

namespace App\Modules\CRM\Enums;

enum CustomerLifecycleStage: string
{
    case Lead = 'lead';
    case Prospect = 'prospect';
    case Customer = 'customer';
    case Inactive = 'inactive';
    case Churned = 'churned';
}
