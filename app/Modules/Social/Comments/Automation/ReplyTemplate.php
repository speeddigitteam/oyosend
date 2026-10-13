<?php

declare(strict_types=1);

namespace App\Modules\Social\Comments\Automation;

use App\Modules\Social\Comments\Support\UntrustedText;

/**
 * A reply template: plain text with a fixed allow-list of `{variables}`. Nothing is evaluated: values are put in
 * by a single literal substitution, so a commenter whose name contains a brace can never smuggle in another variable.
 */
final class ReplyTemplate
{
    /** @var list<string> */
    public const array VARIABLES = ['first_name', 'page_name'];

    /**
     * Variables in the template that are not on the allow-list (for validation).
     *
     * @return list<string>
     */
    public static function unknownVariables(string $template): array
    {
        preg_match_all('/\{([^{}\s]{0,40})\}/u', $template, $found);

        return array_values(array_unique(array_filter(
            $found[1],
            static fn (string $name): bool => ! in_array($name, self::VARIABLES, true),
        )));
    }

    public static function render(string $template, ?string $authorName, string $pageName): string
    {
        $first = explode(' ', UntrustedText::line($authorName, 190))[0];
        $first = mb_substr(str_replace(['{', '}'], '', $first), 0, 40);

        $text = strtr($template, ['{first_name}' => $first, '{page_name}' => str_replace(['{', '}'], '', $pageName)]);
        // "Hi {first_name}, thanks" with no name must not leave "Hi , thanks".
        $text = preg_replace('/\s+([,.!?;:])/u', '$1', $text) ?? $text;

        return UntrustedText::clean(preg_replace('/[ \t]{2,}/u', ' ', $text) ?? $text, (int) config('social_comments.limits.reply_max', 2000));
    }
}
