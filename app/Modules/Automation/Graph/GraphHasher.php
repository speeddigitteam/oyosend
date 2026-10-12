<?php

declare(strict_types=1);

namespace App\Modules\Automation\Graph;

/** The content hash of a published graph: stable regardless of key order, so it identifies exactly that logic. */
final class GraphHasher
{
    /**
     * @param  array<string, mixed>  $graph
     */
    public static function hash(array $graph): string
    {
        return hash('sha256', (string) json_encode(self::canonical($graph), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
    }

    private static function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $value = array_map(self::canonical(...), $value);

        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }
}
