<?php

declare(strict_types=1);

namespace App\Modules\Communication\Enums;

enum SuppressionScope: string
{
    /** Blocks every message to the address, transactional ones included. */
    case All = 'all';
    /** Blocks marketing only (an unsubscribe). */
    case Marketing = 'marketing';
}
