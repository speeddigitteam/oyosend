<?php

declare(strict_types=1);

namespace App\Modules\Support\Enums;

/** Who wrote a website chat message: the visitor (`in`) or a member of the workspace (`out`). */
enum ChatDirection: string
{
    case In = 'in';
    case Out = 'out';
}
