<?php

declare(strict_types=1);

namespace App\Modules\Social\Comments\Enums;

/** What the platform last told us about a comment. */
enum RemoteState: string
{
    case Visible = 'visible';
    case Hidden = 'hidden';
    case Deleted = 'deleted';
}
