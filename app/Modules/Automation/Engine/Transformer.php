<?php

declare(strict_types=1);

namespace App\Modules\Automation\Engine;

use InvalidArgumentException;

/**
 * The data transformation node: declarative mappings only.
 *
 * Each mapping names a `target` (a plain key written under `vars`) and one operation from a fixed list.
 * There is no expression language and nothing is evaluated: an operation takes paths and literals and
 * returns a value. Money arithmetic stays in integers; a result that would overflow is refused.
 */
final class Transformer
{
    /**
     * Operation => the mapping fields it reads. The builder offers exactly these.
     *
     * @var array<string, list<string>>
     */
    public const array OPERATIONS = [
        'copy' => ['source'],
        'literal' => ['value'],
        'template' => ['value'],
        'concat' => ['values'],
        'lowercase' => ['source'],
        'uppercase' => ['source'],
        'trim' => ['source'],
        'to_string' => ['source'],
        'to_integer' => ['source'],
        'to_boolean' => ['source'],
        'length' => ['source'],
        'default' => ['source', 'value'],
        'add' => ['values'],
        'subtract' => ['values'],
        'multiply' => ['values'],
        'first' => ['source'],
    ];

    public function __construct(private readonly VariableResolver $variables) {}

    /**
     * @param  list<array<string, mixed>>  $mappings
     * @param  array<string, mixed>  $context
     * @return array<string, mixed> target => value
     */
    public function apply(array $mappings, array $context): array
    {
        $output = [];

        foreach ($mappings as $mapping) {
            $target = (string) ($mapping['target'] ?? '');
            $operation = (string) ($mapping['operation'] ?? '');

            if (preg_match('/^[a-zA-Z_][a-zA-Z0-9_]{0,63}$/D', $target) !== 1 || ! isset(self::OPERATIONS[$operation])) {
                throw new InvalidArgumentException('Invalid mapping.');
            }

            $output[$target] = $this->operate($operation, $mapping, $context);
            // Later mappings of the same node may read what earlier ones produced.
            $context['vars'] = [...(is_array($context['vars'] ?? null) ? $context['vars'] : []), $target => $output[$target]];
        }

        return $output;
    }

    /**
     * @param  array<string, mixed>  $mapping
     * @param  array<string, mixed>  $context
     */
    private function operate(string $operation, array $mapping, array $context): mixed
    {
        $source = $this->source($mapping['source'] ?? null, $context);

        return match ($operation) {
            'copy' => $source,
            'literal' => $mapping['value'] ?? null,
            'template' => (string) $this->variables->resolve((string) ($mapping['value'] ?? ''), $context),
            'concat' => implode('', array_map(
                static fn (mixed $part): string => is_scalar($part) ? (string) $part : '',
                $this->values($mapping, $context),
            )),
            'lowercase' => is_scalar($source) ? mb_strtolower((string) $source) : null,
            'uppercase' => is_scalar($source) ? mb_strtoupper((string) $source) : null,
            'trim' => is_scalar($source) ? trim((string) $source) : null,
            'to_string' => is_scalar($source) ? (is_bool($source) ? ($source ? 'true' : 'false') : (string) $source) : null,
            'to_integer' => is_numeric($source) ? (int) $source : null,
            'to_boolean' => $this->toBoolean($source),
            'length' => is_array($source) ? count($source) : (is_scalar($source) ? mb_strlen((string) $source) : 0),
            'default' => $source === null || $source === '' ? $this->variables->resolve($mapping['value'] ?? null, $context) : $source,
            'add', 'subtract', 'multiply' => $this->arithmetic($operation, $this->values($mapping, $context)),
            'first' => is_array($source) && $source !== [] ? reset($source) : null,
            default => throw new InvalidArgumentException('Unknown operation.'),
        };
    }

    /** @param  array<string, mixed>  $context */
    private function source(mixed $source, array $context): mixed
    {
        if (! is_string($source) || $source === '') {
            return null;
        }

        return str_contains($source, '{{')
            ? $this->variables->resolve($source, $context)
            : $this->variables->lookup($source, $context)[1];
    }

    /**
     * @param  array<string, mixed>  $mapping
     * @param  array<string, mixed>  $context
     * @return list<mixed>
     */
    private function values(array $mapping, array $context): array
    {
        $values = is_array($mapping['values'] ?? null) ? array_values($mapping['values']) : [];

        return array_map(function (mixed $value) use ($context): mixed {
            if (is_string($value) && preg_match('/^'.VariableResolver::PATH.'$/D', $value) === 1
                && in_array(explode('.', $value)[0], VariableResolver::ROOTS, true)) {
                return $this->variables->lookup($value, $context)[1];
            }

            return $this->variables->resolve($value, $context);
        }, $values);
    }

    /** @param  list<mixed>  $values */
    private function arithmetic(string $operation, array $values): int
    {
        if ($values === []) {
            throw new InvalidArgumentException('An arithmetic mapping needs values.');
        }

        $numbers = [];

        foreach ($values as $value) {
            if (is_int($value) || (is_string($value) && preg_match('/^-?\d{1,18}$/D', $value) === 1)) {
                $numbers[] = (int) $value;

                continue;
            }

            throw new InvalidArgumentException('Arithmetic works on whole numbers only (money is in minor units).');
        }

        $result = array_shift($numbers);

        foreach ($numbers as $number) {
            // Judge the exact result in floating point first, so an overflow is refused instead of wrapping.
            $exact = match ($operation) {
                'add' => (float) $result + (float) $number,
                'subtract' => (float) $result - (float) $number,
                default => (float) $result * (float) $number,
            };

            if (abs($exact) > 9.0E18) {
                throw new InvalidArgumentException('Arithmetic result is out of range.');
            }

            $result = match ($operation) {
                'add' => $result + $number,
                'subtract' => $result - $number,
                default => $result * $number,
            };
        }

        return $result;
    }

    private function toBoolean(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return match (is_string($value) ? strtolower($value) : $value) {
            1, '1', 'true', 'yes', 'on' => true,
            0, '0', 'false', 'no', 'off' => false,
            default => null,
        };
    }
}
