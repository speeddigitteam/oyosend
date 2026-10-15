<?php

declare(strict_types=1);

namespace App\Core\Website\Enums;

/**
 * Where a blog article stands. A published article with a publishing time still ahead is scheduled: it appears on
 * its own when that time comes, with nothing run on a schedule.
 */
enum PostStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
}
