<?php

declare(strict_types=1);

namespace App\Modules\Reports\Enums;

/**
 * Who besides the owner can open a saved report. Sharing shares the report's DEFINITION only:
 * whoever opens it still runs it with their own permission and record scope.
 */
enum Visibility: string
{
    case Private = 'private';
    case Role = 'role';
    case Department = 'department';
}
