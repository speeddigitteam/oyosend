<?php

declare(strict_types=1);

namespace App\Modules\Social\Enums;

enum CampaignStatus: string
{
    case Active = 'active';
    case Archived = 'archived';
}
