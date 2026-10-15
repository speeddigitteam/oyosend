<?php

declare(strict_types=1);

namespace App\Modules\Support\Support;

/**
 * Cleans text a website visitor or a member typed into the live chat. The result is plain text that is stored as is
 * and always rendered as text; nothing here makes it safe for HTML, and nothing has to.
 */
final class ChatText
{
    /**
     * C0 and C1 control characters except line feed, DEL, the byte-order mark and the bidirectional embedding,
     * override and isolate characters (which can make a message display differently from what it says). Zero-width
     * joiners stay: Bengali and emoji need them.
     */
    private const string STRIP = '/[\x{0000}-\x{0009}\x{000B}\x{000C}\x{000E}-\x{001F}\x{007F}-\x{009F}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{FEFF}]/u';

    /**
     * Message text: line breaks normalised to `\n`, tabs to spaces, the characters above removed, at most one empty
     * line in a row, trimmed. Null when the input is not valid UTF-8.
     */
    public static function message(string $text): ?string
    {
        if (! mb_check_encoding($text, 'UTF-8')) {
            return null;
        }

        $text = str_replace(["\r\n", "\r", "\u{2028}", "\u{2029}", "\t"], ["\n", "\n", "\n", "\n", ' '], $text);
        $text = (string) preg_replace(self::STRIP, '', $text);
        $text = (string) preg_replace("/\n{3,}/", "\n\n", $text);

        return trim($text);
    }

    /** One line (a name): as {@see message()}, with every run of white space a single space. */
    public static function line(string $text): ?string
    {
        $text = self::message($text);

        return $text === null ? null : trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
