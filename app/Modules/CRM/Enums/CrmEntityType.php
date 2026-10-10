<?php

declare(strict_types=1);

namespace App\Modules\CRM\Enums;

enum CrmEntityType: string
{
    case Lead = 'lead';
    case Deal = 'deal';
    case Activity = 'activity';
    case Contact = 'contact';
    case Company = 'company';
}
