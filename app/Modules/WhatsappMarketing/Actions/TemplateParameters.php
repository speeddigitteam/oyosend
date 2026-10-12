<?php

declare(strict_types=1);

namespace App\Modules\WhatsappMarketing\Actions;

/**
 * The `{{1}}`, `{{2}}` positional variables of a WhatsApp template body. Substitution only: no expressions, no
 * filters, nothing is evaluated.
 */
final class TemplateParameters
{
    public const int MAX = 20;

    /**
     * The variable positions a body uses, ascending.
     *
     * @return list<int>
     */
    public static function positions(string $body): array
    {
        preg_match_all('/\{\{\s*(\d{1,2})\s*\}\}/', $body, $matches);

        $positions = array_values(array_unique(array_map('intval', $matches[1])));
        sort($positions);

        return $positions;
    }

    /**
     * Whether positions are exactly 1..n, as the provider requires.
     *
     * @param  list<int>  $positions
     */
    public static function isSequential(array $positions): bool
    {
        return $positions === [] || $positions === range(1, count($positions));
    }

    /**
     * @param  list<string>  $parameters  Value of `{{1}}` first.
     */
    public static function render(string $body, array $parameters): string
    {
        return (string) preg_replace_callback(
            '/\{\{\s*(\d{1,2})\s*\}\}/',
            static fn (array $match): string => $parameters[((int) $match[1]) - 1] ?? '',
            $body,
        );
    }
}
