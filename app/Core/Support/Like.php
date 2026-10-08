<?php

declare(strict_types=1);

namespace App\Core\Support;

/**
 * Text typed by a person, made safe to put inside a SQL `LIKE` pattern.
 */
final class Like
{
    /**
     * Escapes the pattern characters, so `50%` and `first_name` are searched
     * for as written instead of acting as wildcards.
     */
    public static function escape(string $value): string
    {
        return addcslashes($value, '\\%_');
    }

    public static function contains(string $value): string
    {
        return '%'.self::escape($value).'%';
    }
}
