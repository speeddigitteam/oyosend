<?php

declare(strict_types=1);

namespace App\Modules\Ai\Support;

/** A value of a proposed change as plain, length-capped text for the confirmation card. */
final class ChangeValue
{
    public const int MAX_LENGTH = 200;

    public static function display(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_scalar($value)) {
            return null;
        }

        $text = trim((string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', (string) $value));

        return mb_strlen($text) > self::MAX_LENGTH ? mb_substr($text, 0, self::MAX_LENGTH).'…' : $text;
    }
}
