<?php

declare(strict_types=1);

namespace App\Modules\Social\Enums;

enum AttemptOutcome: string
{
    case Success = 'success';
    case Retry = 'retry';
    case Failed = 'failed';
    case Deferred = 'deferred';
}
