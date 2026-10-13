<?php

declare(strict_types=1);

namespace App\Modules\MetaAds\Enums;

enum ObjectLevel: string
{
    case Campaign = 'campaign';
    case AdSet = 'adset';
    case Ad = 'ad';

    /** The Marketing API edge on an ad account. */
    public function edge(): string
    {
        return match ($this) {
            self::Campaign => 'campaigns',
            self::AdSet => 'adsets',
            self::Ad => 'ads',
        };
    }
}
