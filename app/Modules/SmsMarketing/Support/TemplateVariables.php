<?php

declare(strict_types=1);

namespace App\Modules\SmsMarketing\Support;

use App\Modules\SmsMarketing\Enums\TemplatePurpose;

/**
 * The fixed allow-list of template variables, and the only renderer. A variable is `{{name}}` or
 * `{{name|fallback}}` (the fallback is plain text of up to 30 characters, used when the contact has no value).
 *
 * Nothing is evaluated: a variable is looked up in an array of plain strings. `code` and `otp` are one-time
 * code placeholders; they exist only for transactional templates and a marketing text containing them is
 * refused.
 */
final class TemplateVariables
{
    public const array PERSONAL = ['first_name', 'last_name', 'full_name', 'company_name'];

    public const array ONE_TIME = ['code', 'otp'];

    public const int FALLBACK_MAX = 30;

    public const int VALUE_MAX = 40;

    private const string TOKEN = '/\{\{\s*([a-z_]{1,32})\s*(?:\|([^{}|]{0,30}))?\}\}/u';

    /** @return list<string> */
    public static function allowed(TemplatePurpose $purpose): array
    {
        return $purpose === TemplatePurpose::Transactional ? [...self::PERSONAL, ...self::ONE_TIME] : self::PERSONAL;
    }

    /**
     * Every problem with a text's variables. An empty result means the text can be saved.
     *
     * @return array{unknown: list<string>, oneTimeInMarketing: bool, malformed: bool}
     */
    public static function inspect(string $text, TemplatePurpose $purpose): array
    {
        $unknown = [];
        $oneTime = false;

        preg_match_all(self::TOKEN, $text, $matches);

        foreach ($matches[1] as $name) {
            if (in_array($name, self::ONE_TIME, true) && $purpose === TemplatePurpose::Marketing) {
                $oneTime = true;
            } elseif (! in_array($name, self::allowed($purpose), true)) {
                $unknown[] = $name;
            }
        }

        $rest = preg_replace(self::TOKEN, '', $text) ?? $text;

        return [
            'unknown' => array_values(array_unique($unknown)),
            'oneTimeInMarketing' => $oneTime,
            'malformed' => str_contains($rest, '{{') || str_contains($rest, '}}'),
        ];
    }

    /** @return list<string> The variables a text uses, once each. */
    public static function used(string $text): array
    {
        preg_match_all(self::TOKEN, $text, $matches);

        return array_values(array_unique($matches[1]));
    }

    /**
     * @param  array<string, string|null>  $values  Keyed by variable name; missing or blank means "not known".
     */
    public static function render(string $text, array $values): string
    {
        $rendered = preg_replace_callback(self::TOKEN, static function (array $match) use ($values): string {
            $value = self::clean($values[$match[1]] ?? null);

            if ($value !== '') {
                return $value;
            }

            return self::clean($match[2] ?? null);
        }, $text) ?? $text;

        // A missing value must not leave a gap of several spaces.
        return trim(preg_replace('/[ \t]{2,}/u', ' ', $rendered) ?? $rendered);
    }

    /**
     * Illustrative values used for previews and estimates.
     *
     * @return array<string, string>
     */
    public static function sampleValues(): array
    {
        return ['first_name' => 'Rahim', 'last_name' => 'Uddin', 'full_name' => 'Rahim Uddin', 'company_name' => 'Acme Ltd', 'code' => '123456', 'otp' => '123456'];
    }

    private static function clean(?string $value): string
    {
        $value = trim((string) $value);
        $value = preg_replace('/[\p{Cc}\p{Cf}]+/u', ' ', $value) ?? '';

        return trim(mb_substr($value, 0, self::VALUE_MAX));
    }
}
