<?php

declare(strict_types=1);

namespace App\Modules\WhatsappMarketing\Support;

/** Untrusted-text hygiene shared by everything that stores a customer's or an agent's words. */
final class Text
{
    /**
     * Valid UTF-8, no control characters (newline and tab kept when `$multiline`), trimmed, at most `$max`
     * characters. The result is plain text: it is only ever rendered as text, never as markup.
     */
    public static function clean(?string $text, int $max, bool $multiline = true): string
    {
        $text = (string) $text;

        if (! mb_check_encoding($text, 'UTF-8')) {
            $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        }

        $text = preg_replace($multiline ? '/[^\P{C}\n\t]+/u' : '/\p{C}+/u', '', $text) ?? '';
        $text = str_replace("\r", '', $text);
        $text = $multiline ? $text : (preg_replace('/\s+/u', ' ', $text) ?? '');

        return mb_substr(trim($text), 0, $max);
    }

    /** One line of at most `$max` characters, ending in an ellipsis when it was cut. */
    public static function preview(string $text, int $max = 160): string
    {
        $line = self::clean($text, 4096, false);

        return mb_strlen($line) > $max ? mb_substr($line, 0, $max - 1).'…' : $line;
    }
}
