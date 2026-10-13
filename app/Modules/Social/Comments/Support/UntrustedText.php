<?php

declare(strict_types=1);

namespace App\Modules\Social\Comments\Support;

/**
 * Text that came from outside the workspace (a Facebook comment, an author name). It is stored and shown
 * as plain text only; this removes what has no business in it: invalid UTF-8, control characters and the
 * bidirectional override characters that can reorder what a reader sees. Joiners (U+200C / U+200D) stay:
 * Bengali conjuncts and emoji need them.
 */
final class UntrustedText
{
    public static function clean(mixed $value, int $max): string
    {
        if (! is_string($value) && ! is_int($value)) {
            return '';
        }

        $text = (string) $value;

        if (! mb_check_encoding($text, 'UTF-8')) {
            $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        }

        $text = str_replace(["\r\n", "\r"], "\n", $text);
        // C0 controls except tab and newline, DEL, C1 controls, bidi embeddings/overrides/isolates, BOM, line/paragraph separators.
        $text = preg_replace('/[\x{0000}-\x{0008}\x{000B}\x{000C}\x{000E}-\x{001F}\x{007F}-\x{009F}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{FEFF}\x{2028}\x{2029}]/u', '', $text) ?? '';
        $text = preg_replace("/\n{4,}/", "\n\n\n", $text) ?? $text;

        return mb_substr(trim($text), 0, max(0, $max));
    }

    /** A single-line label (names, tags): whitespace collapsed. */
    public static function line(mixed $value, int $max): string
    {
        return mb_substr(trim(preg_replace('/\s+/u', ' ', self::clean($value, $max * 2)) ?? ''), 0, max(0, $max));
    }
}
