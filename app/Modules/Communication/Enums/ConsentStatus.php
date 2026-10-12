<?php

declare(strict_types=1);

namespace App\Modules\Communication\Enums;

enum ConsentStatus: string
{
    case OptedIn = 'opted_in';
    case OptedOut = 'opted_out';
}
