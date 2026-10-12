<?php

declare(strict_types=1);

namespace App\Modules\Support\Enums;

enum ArticleStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Archived = 'archived';
}
