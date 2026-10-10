<?php

declare(strict_types=1);

namespace App\Modules\CRM\Enums;

enum CatalogItemType: string
{
    case Product = 'product';
    case Service = 'service';
}
