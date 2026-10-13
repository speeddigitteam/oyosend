<?php

declare(strict_types=1);

namespace App\Modules\Ai\Support;

use App\Core\Ai\Text\Sentiment;
use App\Core\Ai\Text\TextKind;
use App\Core\Ai\Text\TextTask;

/**
 * Turns raw model output into the bounded, typed fields of a TextResult. Model output is untrusted: text is
 * stripped of tags and control characters and cut to the task's length; hashtags are normalised; sentiment must
 * be a strict JSON object with known values, otherwise it is `unknown`.
 *
 * @phpstan-type Parsed array{text: string, hashtags: list<string>, sentiment: ?Sentiment, score: ?int, language: ?string, usable: bool}
 */
final class TextOutputParser
{
    /** Hard cap on generated text whatever the task asked for. */
    public const int MAX_OUTPUT_CHARS = 2000;

    public const int MAX_HASHTAG_CHARS = 50;

    /**
     * @return Parsed
     */
    public static function parse(TextTask $task, string $raw): array
    {
        return match ($task->kind) {
            TextKind::Hashtags => self::hashtags($task, $raw),
            TextKind::Sentiment => self::sentiment($raw),
            default => self::plain($task, $raw),
        };
    }

    /**
     * @return Parsed
     */
    private static function plain(TextTask $task, string $raw): array
    {
        $text = self::clean($raw);
        $cap = min($task->maxLength ?? self::MAX_OUTPUT_CHARS, self::MAX_OUTPUT_CHARS);
        $text = self::cut($text, $cap);

        return ['text' => $text, 'hashtags' => [], 'sentiment' => null, 'score' => null, 'language' => null, 'usable' => $text !== ''];
    }

    /**
     * @return Parsed
     */
    private static function hashtags(TextTask $task, string $raw): array
    {
        $limit = $task->maxItems ?? 10;
        $tags = [];

        // Only tokens that start with # count: a preface such as "Here are some tags" is not a hashtag.
        if (preg_match_all('/(?<![\p{L}\p{N}_])#([\p{L}\p{N}_]{1,'.self::MAX_HASHTAG_CHARS.'})(?![\p{L}\p{N}_])/u', self::clean($raw), $matches) > 0) {
            foreach ($matches[1] as $tag) {
                $tag = mb_strtolower((string) $tag);

                if (preg_match('/^\d+$/', $tag) === 1 || in_array($tag, $tags, true)) {
                    continue;
                }

                $tags[] = $tag;

                if (count($tags) >= $limit) {
                    break;
                }
            }
        }

        $text = implode(' ', array_map(static fn (string $tag): string => '#'.$tag, $tags));

        return ['text' => $text, 'hashtags' => $tags, 'sentiment' => null, 'score' => null, 'language' => null, 'usable' => $tags !== []];
    }

    /**
     * @return Parsed
     */
    private static function sentiment(string $raw): array
    {
        $unknown = ['text' => Sentiment::Unknown->value, 'hashtags' => [], 'sentiment' => Sentiment::Unknown, 'score' => null, 'language' => null, 'usable' => true];
        $json = trim((string) preg_replace('/^```(?:json)?\s*|\s*```$/i', '', trim($raw)));
        $data = json_decode($json, true, 4);

        if (! is_array($data)) {
            $start = strpos($json, '{');
            $end = strrpos($json, '}');
            $data = ($start !== false && $end !== false && $end > $start)
                ? json_decode(substr($json, $start, $end - $start + 1), true, 4)
                : null;
        }

        if (! is_array($data) || array_is_list($data)) {
            return $unknown;
        }

        $label = is_string($data['sentiment'] ?? null) ? Sentiment::tryFrom(strtolower(trim($data['sentiment']))) : null;

        if ($label === null || $label === Sentiment::Unknown) {
            return $unknown;
        }

        $score = null;

        if (is_int($data['score'] ?? null) || is_float($data['score'] ?? null)) {
            $value = (float) $data['score'];

            if (is_finite($value) && $value >= -1.0 && $value <= 1.0) {
                $score = (int) round($value * 10000);
            }
        }

        // A label and a score that contradict each other are not trusted.
        if ($score !== null && (($label === Sentiment::Positive && $score < 0) || ($label === Sentiment::Negative && $score > 0))) {
            return $unknown;
        }

        $language = is_string($data['language'] ?? null) && preg_match('/^[a-z]{2,3}$/', strtolower(trim($data['language']))) === 1
            ? strtolower(trim($data['language']))
            : null;

        return ['text' => $label->value, 'hashtags' => [], 'sentiment' => $label, 'score' => $score, 'language' => $language, 'usable' => true];
    }

    private static function clean(string $raw): string
    {
        $text = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $raw);
        $text = (string) preg_replace('/^```[a-z]*\s*|\s*```$/i', '', trim($text));
        $text = (string) preg_replace('~</?[A-Za-z][^>]{0,200}>~', '', $text);
        $text = (string) preg_replace("/\r\n?/", "\n", $text);
        $text = (string) preg_replace("/\n{3,}/", "\n\n", $text);

        return trim($text);
    }

    private static function cut(string $text, int $limit): string
    {
        if (mb_strlen($text) <= $limit) {
            return $text;
        }

        $cut = mb_substr($text, 0, $limit);
        $space = mb_strrpos($cut, ' ');

        // Prefer a word boundary when it does not throw away more than a fifth.
        if ($space !== false && $space >= (int) ($limit * 0.8)) {
            $cut = mb_substr($cut, 0, $space);
        }

        return rtrim($cut);
    }
}
