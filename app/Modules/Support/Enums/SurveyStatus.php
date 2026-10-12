<?php

declare(strict_types=1);

namespace App\Modules\Support\Enums;

enum SurveyStatus: string
{
    case Pending = 'pending';
    case Answered = 'answered';
    case Expired = 'expired';
}
