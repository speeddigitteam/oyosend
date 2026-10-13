<?php

declare(strict_types=1);

namespace App\Modules\Social\Comments\Support;

/**
 * Keyword matching for comment rules. Keywords are data, never patterns: nothing a member types is ever
 * compiled into a regular expression, so there is no backtracking to attack. Matching is case-insensitive
 * and language-agnostic (letters, digits and combining marks of any script, so Bengali conjuncts stay whole).
 *
 *  - `word`     the keyword (one or more words) must appear as whole words, in order, next to each other;
 *  - `contains` the keyword may appear anywhere, also inside another word.
 */
final class KeywordMatcher
{
    public const int MAX_KEYWORDS = 50;

    public const int MAX_KEYWORD_LENGTH = 60;

    public const int MAX_TEXT_LENGTH = 8000;

    /**
     * Keywords as stored: trimmed, single-spaced, de-duplicated case-insensitively, within the limits.
     *
     * @param  array<array-key, mixed>  $keywords
     * @return list<string>
     */
    public static function sanitize(array $keywords): array
    {
        $clean = [];
        $seen = [];

        foreach ($keywords as $keyword) {
            if (! is_string($keyword)) {
                continue;
            }

            $value = UntrustedText::line($keyword, self::MAX_KEYWORD_LENGTH);
            $key = self::normalise($value);

            if ($value === '' || $key === '' || isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $clean[] = $value;

            if (count($clean) >= self::MAX_KEYWORDS) {
                break;
            }
        }

        return $clean;
    }

    /**
     * @param  list<string>  $keywords
     * @return array{matched: bool, hits: list<string>}
     */
    public static function evaluate(string $text, array $keywords, string $mode = 'any', string $type = 'word'): array
    {
        $text = mb_substr($text, 0, self::MAX_TEXT_LENGTH);
        $normal = self::normalise($text);
        $tokens = self::tokens($normal);
        $hits = [];
        $considered = 0;

        foreach ($keywords as $keyword) {
            $needle = self::normalise($keyword);

            if ($needle === '') {
                continue;
            }

            $considered++;
            $found = $type === 'contains'
                ? str_contains($normal, $needle)
                : self::containsTokens($tokens, self::tokens($needle));

            if ($found) {
                $hits[] = $keyword;
            }
        }

        $matched = $considered > 0 && ($mode === 'all' ? count($hits) === $considered : $hits !== []);

        return ['matched' => $matched, 'hits' => $hits];
    }

    public static function normalise(string $text): string
    {
        // Joiners only affect how a script is drawn; dropping them on both sides keeps matching consistent.
        $text = str_replace(["\u{200C}", "\u{200D}"], '', $text);

        if (class_exists(\Normalizer::class)) {
            $normalised = \Normalizer::normalize($text, \Normalizer::FORM_C);
            $text = is_string($normalised) ? $normalised : $text;
        }

        return trim(preg_replace('/\s+/u', ' ', mb_strtolower($text)) ?? '');
    }

    /** @return list<string> */
    private static function tokens(string $normalised): array
    {
        $parts = preg_split('/[^\p{L}\p{N}\p{M}]+/u', $normalised, -1, PREG_SPLIT_NO_EMPTY);

        return $parts === false ? [] : $parts;
    }

    /**
     * @param  list<string>  $haystack
     * @param  list<string>  $needle
     */
    private static function containsTokens(array $haystack, array $needle): bool
    {
        $length = count($needle);

        if ($length === 0 || $length > count($haystack)) {
            return false;
        }

        for ($start = 0, $last = count($haystack) - $length; $start <= $last; $start++) {
            for ($offset = 0; $offset < $length; $offset++) {
                if ($haystack[$start + $offset] !== $needle[$offset]) {
                    continue 2;
                }
            }

            return true;
        }

        return false;
    }
}
