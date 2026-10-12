<?php

declare(strict_types=1);

namespace App\Modules\Support\Automation\Triggers;

use App\Modules\Support\Events\CsatSubmitted;

final class CsatSubmittedTrigger extends SupportTrigger
{
    public static function key(): string
    {
        return 'support.csat.submitted';
    }

    public static function event(): string
    {
        return CsatSubmitted::class;
    }

    protected static function extraProperties(): array
    {
        return [
            'survey_id' => ['type' => 'string'],
            'rating' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 5],
        ];
    }
}
