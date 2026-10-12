<?php

declare(strict_types=1);

namespace App\Modules\EmailMarketing\Support;

/**
 * The personalisation variables an email may use. The list is fixed: a name outside it is refused when the
 * content is saved, so nothing a person types is ever evaluated.
 *
 * Syntax: `{{first_name}}` or `{{first_name|there}}` (fallback text used when the value is empty).
 */
final class Variables
{
    /** @var list<string> */
    public const array ALLOWED = ['first_name', 'last_name', 'full_name', 'email', 'company', 'workspace_name', 'current_year', 'unsubscribe_url'];

    public const string UNSUBSCRIBE = 'unsubscribe_url';

    private const string TOKEN = '/\{\{\s*([a-z_]+)\s*(?:\|([^{}|]{0,40}))?\}\}/';

    /**
     * Names used in `$text` that are not allowed, including malformed placeholders.
     *
     * @param  list<string>  $allowed
     * @return list<string>
     */
    public static function unknown(string $text, array $allowed = self::ALLOWED): array
    {
        $unknown = [];

        if (preg_match_all('/\{\{(.*?)\}\}/s', $text, $all) > 0) {
            foreach ($all[0] as $placeholder) {
                if (preg_match(self::TOKEN, $placeholder, $match) !== 1 || ! in_array($match[1], $allowed, true) || $match[0] !== $placeholder) {
                    $unknown[] = $placeholder;
                }
            }
        }

        // An unbalanced `{{` is not a variable either, but it would be shown as written: harmless, not an error.
        return array_values(array_unique($unknown));
    }

    /**
     * Replace every allowed variable by its value (raw text; the caller escapes for its output format).
     *
     * @param  array<string, string|null>  $values
     */
    public static function replace(string $text, array $values): string
    {
        return (string) preg_replace_callback(self::TOKEN, static function (array $match) use ($values): string {
            $name = $match[1];

            if (! in_array($name, self::ALLOWED, true)) {
                return '';
            }

            $value = trim((string) ($values[$name] ?? ''));

            return $value !== '' ? $value : trim($match[2] ?? '');
        }, $text);
    }

    /** @return list<array{key: string, label: string}> */
    public static function catalogue(): array
    {
        return array_map(static fn (string $key): array => ['key' => $key, 'label' => $key], self::ALLOWED);
    }
}
