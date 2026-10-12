<?php

declare(strict_types=1);

namespace App\Modules\Support\Support;

/**
 * Fills the allow-listed `{{ placeholders }}` of a canned response. It is a plain, single-pass string
 * substitution: there is no expression language, no method call and no recursion, so a value that itself
 * contains `{{ ... }}` is inserted as text. Unknown placeholders are refused when a response is saved.
 */
final class PlaceholderRenderer
{
    /** @var list<string> */
    public const array ALLOWED = [
        'requester.name',
        'requester.email',
        'ticket.number',
        'ticket.subject',
        'agent.name',
        'workspace.name',
    ];

    private const string PATTERN = '/\{\{\s*([A-Za-z0-9_.\-]*)\s*\}\}/';

    /** @return list<string> The distinct placeholder names in the text that are not on the allow-list. */
    public static function unknown(string $text): array
    {
        preg_match_all(self::PATTERN, $text, $matches);

        return array_values(array_unique(array_filter(
            $matches[1],
            static fn (string $name): bool => ! in_array($name, self::ALLOWED, true),
        )));
    }

    /** Stray braces that do not form a placeholder. */
    public static function hasMalformed(string $text): bool
    {
        $stripped = preg_replace(self::PATTERN, '', $text) ?? $text;

        return str_contains($stripped, '{{') || str_contains($stripped, '}}');
    }

    /**
     * @param  array<string, string|null>  $values  Keyed by allow-listed name.
     */
    public static function render(string $text, array $values): string
    {
        return (string) preg_replace_callback(
            self::PATTERN,
            static function (array $match) use ($values): string {
                $name = $match[1];

                if (! in_array($name, self::ALLOWED, true)) {
                    return $match[0];
                }

                return (string) ($values[$name] ?? '');
            },
            $text,
        );
    }
}
