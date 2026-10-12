<?php

declare(strict_types=1);

namespace App\Modules\Automation\Engine;

use Carbon\CarbonImmutable;
use Throwable;

/**
 * A small checker for the subset of JSON Schema the trigger and action contracts use: `type`, `required`,
 * `properties`, `additionalProperties: false`, `enum`, `minLength`, `maxLength`, `minimum`, `maximum`,
 * `items` and `format: date-time`. Anything else in a schema is ignored, never executed.
 */
final class SchemaChecker
{
    /**
     * @param  array<string, mixed>  $schema
     * @return list<string> Human-readable problems, empty when the value conforms.
     */
    public function check(array $schema, mixed $value, string $path = ''): array
    {
        $errors = [];
        $types = isset($schema['type']) ? (array) $schema['type'] : [];

        if ($types !== [] && ! $this->typeMatches($types, $value)) {
            return [($path === '' ? 'value' : $path).' must be of type '.implode('|', array_map('strval', $types))];
        }

        if (isset($schema['enum']) && is_array($schema['enum']) && ! in_array($value, $schema['enum'], true)) {
            $errors[] = ($path === '' ? 'value' : $path).' must be one of the allowed values';
        }

        if (is_string($value)) {
            if (isset($schema['minLength']) && mb_strlen($value) < (int) $schema['minLength']) {
                $errors[] = "{$path} is too short";
            }

            if (isset($schema['maxLength']) && mb_strlen($value) > (int) $schema['maxLength']) {
                $errors[] = "{$path} is too long";
            }

            if (($schema['format'] ?? null) === 'date-time' && ! $this->isDateTime($value)) {
                $errors[] = "{$path} must be a date-time";
            }
        }

        if ((is_int($value) || is_float($value))) {
            if (isset($schema['minimum']) && $value < $schema['minimum']) {
                $errors[] = "{$path} is below the minimum";
            }

            if (isset($schema['maximum']) && $value > $schema['maximum']) {
                $errors[] = "{$path} is above the maximum";
            }
        }

        if (is_array($value) && isset($schema['items']) && is_array($schema['items']) && array_is_list($value)) {
            foreach ($value as $index => $item) {
                array_push($errors, ...$this->check($schema['items'], $item, $path.'['.$index.']'));
            }
        }

        if (is_array($value) && ! array_is_list($value) || $value === [] && in_array('object', $types, true)) {
            $properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];

            foreach ((array) ($schema['required'] ?? []) as $required) {
                if (! array_key_exists((string) $required, (array) $value)) {
                    $errors[] = ($path === '' ? '' : $path.'.').$required.' is required';
                }
            }

            foreach ((array) $value as $key => $item) {
                $key = (string) $key;

                if (isset($properties[$key]) && is_array($properties[$key])) {
                    array_push($errors, ...$this->check($properties[$key], $item, ($path === '' ? '' : $path.'.').$key));
                } elseif (($schema['additionalProperties'] ?? true) === false) {
                    $errors[] = ($path === '' ? '' : $path.'.').$key.' is not allowed';
                }
            }
        }

        return $errors;
    }

    /** @param  list<mixed>  $types */
    private function typeMatches(array $types, mixed $value): bool
    {
        foreach ($types as $type) {
            $matches = match ($type) {
                'string' => is_string($value),
                'integer' => is_int($value),
                'number' => is_int($value) || is_float($value),
                'boolean' => is_bool($value),
                'array' => is_array($value) && array_is_list($value),
                'object' => is_array($value) && ($value === [] || ! array_is_list($value)),
                'null' => $value === null,
                default => false,
            };

            if ($matches) {
                return true;
            }
        }

        return false;
    }

    private function isDateTime(string $value): bool
    {
        try {
            CarbonImmutable::parse($value);

            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
