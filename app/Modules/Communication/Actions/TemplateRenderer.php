<?php

declare(strict_types=1);

namespace App\Modules\Communication\Actions;

/**
 * Safe placeholder substitution. This is deliberately not a template engine: the only operation is replacing
 * `{{ group.name }}` with a plain string from a fixed allow-list, in one pass, with no expressions, filters,
 * includes, Blade or PHP. A value that itself looks like a placeholder is NOT expanded again.
 */
final class TemplateRenderer
{
    private const string TOKEN = '/\{\{(.*?)\}\}/s';

    private const int MAX_VALUE_LENGTH = 500;

    public function __construct(private readonly TemplateVariables $variables) {}

    /**
     * The `{{ ... }}` tokens in the text that are not on the allow-list.
     *
     * @return list<string>
     */
    public function unknownTokens(string $text): array
    {
        preg_match_all(self::TOKEN, $text, $matches);

        $unknown = [];

        foreach ($matches[1] as $inner) {
            $name = trim($inner);

            if (! $this->variables->has($name)) {
                $unknown[$name] = $name;
            }
        }

        return array_values($unknown);
    }

    /**
     * @param  array<string, scalar|null>  $values  Keyed by placeholder (`contact.first_name`); anything not
     *                                              on the allow-list is ignored.
     */
    public function render(string $text, array $values): string
    {
        return (string) preg_replace_callback(self::TOKEN, function (array $match) use ($values): string {
            $name = trim($match[1]);

            if (! $this->variables->has($name)) {
                return '';
            }

            return $this->clean($values[$name] ?? '');
        }, $text);
    }

    private function clean(mixed $value): string
    {
        $string = is_scalar($value) ? (string) $value : '';
        // No control characters: a value must not smuggle a line break into a subject or a header.
        $string = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $string);

        return mb_substr($string, 0, self::MAX_VALUE_LENGTH);
    }
}
