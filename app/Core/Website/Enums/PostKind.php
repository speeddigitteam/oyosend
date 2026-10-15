<?php

declare(strict_types=1);

namespace App\Core\Website\Enums;

/**
 * What kind of article a blog post is: the topics a reader filters the blog by.
 */
enum PostKind: string
{
    /** How to do something, step by step. */
    case Guide = 'guide';

    /** What changed in the product. */
    case Update = 'update';

    /** Running a business: ideas, rules and numbers worth knowing. */
    case Insight = 'insight';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $kind): string => $kind->value, self::cases());
    }
}
