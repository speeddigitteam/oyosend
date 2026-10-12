<?php

declare(strict_types=1);

namespace App\Modules\Automation\Engine;

/**
 * Looks values up in a run's context and fills `{{ path }}` templates.
 *
 * This is deliberately not an expression language: a template is a dotted path into the run context and
 * nothing else - no operators, no function calls, no evaluation. A string that is exactly one template keeps
 * the value's type (an integer stays an integer); a template inside longer text is replaced by the value's
 * scalar text (arrays and objects become an empty string).
 */
final class VariableResolver
{
    /** Roots a template may start from. Everything a run knows is under one of these. */
    public const array ROOTS = ['trigger', 'steps', 'vars', 'loop', 'run', 'workflow', 'error', 'approval'];

    public const string PATH = '[a-zA-Z_][a-zA-Z0-9_]*(?:\.[a-zA-Z0-9_\-]+)*';

    private const string TEMPLATE = '/\{\{\s*('.self::PATH.')\s*\}\}/';

    /**
     * @param  array<string, mixed>  $context
     * @return array{0: bool, 1: mixed} found, value
     */
    public function lookup(string $path, array $context): array
    {
        $current = $context;

        foreach (explode('.', $path) as $segment) {
            if (! is_array($current) || ! array_key_exists($segment, $current)) {
                return [false, null];
            }

            $current = $current[$segment];
        }

        return [true, $current];
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function resolve(mixed $value, array $context): mixed
    {
        if (is_array($value)) {
            $resolved = [];

            foreach ($value as $key => $item) {
                $resolved[$key] = $this->resolve($item, $context);
            }

            return $resolved;
        }

        if (! is_string($value) || ! str_contains($value, '{{')) {
            return $value;
        }

        if (preg_match('/^\s*\{\{\s*('.self::PATH.')\s*\}\}\s*$/D', $value, $match) === 1) {
            return $this->lookup($match[1], $context)[1];
        }

        return (string) preg_replace_callback(self::TEMPLATE, function (array $match) use ($context): string {
            $found = $this->lookup($match[1], $context)[1];

            return is_scalar($found) ? (is_bool($found) ? ($found ? 'true' : 'false') : (string) $found) : '';
        }, $value);
    }

    /**
     * Every path a value references, for publish-time validation.
     *
     * @return list<string>
     */
    public function references(mixed $value): array
    {
        $paths = [];

        if (is_array($value)) {
            foreach ($value as $item) {
                array_push($paths, ...$this->references($item));
            }

            return $paths;
        }

        if (is_string($value) && preg_match_all(self::TEMPLATE, $value, $matches) > 0) {
            return array_values(array_unique($matches[1]));
        }

        return $paths;
    }

    /** Whether the value is (or contains) a template, i.e. only known at run time. */
    public function isDynamic(mixed $value): bool
    {
        return $this->references($value) !== [];
    }
}
