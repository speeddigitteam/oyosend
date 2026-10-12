<?php

declare(strict_types=1);

namespace App\Modules\Ai\Support;

/**
 * Keeps what a tool returns small: long text fields are cut and the whole result is bounded.
 */
final class OutputLimiter
{
    /**
     * @param  array<string, mixed>  $data
     */
    public static function json(array $data, int $maxChars, int $fieldChars): string
    {
        /** @var array<string, mixed> $data */
        $data = self::cutStrings($data, $fieldChars);
        $encoded = self::encode($data);

        if (mb_strlen($encoded) <= $maxChars) {
            return $encoded;
        }

        // Drop trailing list entries until it fits; mark the result as incomplete.
        foreach (['items', 'records'] as $key) {
            if (isset($data[$key]) && is_array($data[$key])) {
                $data['truncated'] = true;

                while ($data[$key] !== [] && mb_strlen(self::encode($data)) > $maxChars) {
                    array_pop($data[$key]);
                }

                if (mb_strlen(self::encode($data)) <= $maxChars) {
                    return self::encode($data);
                }
            }
        }

        return self::encode(['truncated' => true, 'preview' => mb_substr($encoded, 0, max(0, $maxChars - 60))]);
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    private static function cutStrings(array $data, int $limit): array
    {
        foreach ($data as $key => $value) {
            if (is_string($value) && mb_strlen($value) > $limit) {
                $data[$key] = mb_substr($value, 0, $limit).'...';
            } elseif (is_array($value)) {
                $data[$key] = self::cutStrings($value, $limit);
            }
        }

        return $data;
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    private static function encode(array $data): string
    {
        return (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_PARTIAL_OUTPUT_ON_ERROR);
    }
}
