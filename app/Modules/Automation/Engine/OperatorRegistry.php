<?php

declare(strict_types=1);

namespace App\Modules\Automation\Engine;

use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Throwable;

/**
 * The typed condition operators a workflow may use. They are a closed, first-party list: a condition names
 * an operator by key and nothing a tenant writes is ever evaluated as code or as an expression.
 *
 * Each definition says which value types it makes sense for (`string`, `number`, `date`, `boolean`, `list`,
 * `any`) and how many operands it takes on the right-hand side, so the builder can offer the right choices
 * and the validator can reject a nonsensical rule at publish time.
 */
final class OperatorRegistry
{
    /** @var array<string, array{label: string, types: list<string>, arity: string}> */
    private const array DEFINITIONS = [
        'equals' => ['label' => 'automation.operators.equals', 'types' => ['any'], 'arity' => 'one'],
        'not_equals' => ['label' => 'automation.operators.not_equals', 'types' => ['any'], 'arity' => 'one'],
        'gt' => ['label' => 'automation.operators.gt', 'types' => ['number'], 'arity' => 'one'],
        'gte' => ['label' => 'automation.operators.gte', 'types' => ['number'], 'arity' => 'one'],
        'lt' => ['label' => 'automation.operators.lt', 'types' => ['number'], 'arity' => 'one'],
        'lte' => ['label' => 'automation.operators.lte', 'types' => ['number'], 'arity' => 'one'],
        'between' => ['label' => 'automation.operators.between', 'types' => ['number', 'date'], 'arity' => 'two'],
        'contains' => ['label' => 'automation.operators.contains', 'types' => ['string', 'list'], 'arity' => 'one'],
        'not_contains' => ['label' => 'automation.operators.not_contains', 'types' => ['string', 'list'], 'arity' => 'one'],
        'starts_with' => ['label' => 'automation.operators.starts_with', 'types' => ['string'], 'arity' => 'one'],
        'ends_with' => ['label' => 'automation.operators.ends_with', 'types' => ['string'], 'arity' => 'one'],
        'in' => ['label' => 'automation.operators.in', 'types' => ['any'], 'arity' => 'list'],
        'not_in' => ['label' => 'automation.operators.not_in', 'types' => ['any'], 'arity' => 'list'],
        'is_empty' => ['label' => 'automation.operators.is_empty', 'types' => ['any'], 'arity' => 'none'],
        'is_not_empty' => ['label' => 'automation.operators.is_not_empty', 'types' => ['any'], 'arity' => 'none'],
        'exists' => ['label' => 'automation.operators.exists', 'types' => ['any'], 'arity' => 'none'],
        'not_exists' => ['label' => 'automation.operators.not_exists', 'types' => ['any'], 'arity' => 'none'],
        'is_true' => ['label' => 'automation.operators.is_true', 'types' => ['boolean'], 'arity' => 'none'],
        'is_false' => ['label' => 'automation.operators.is_false', 'types' => ['boolean'], 'arity' => 'none'],
        'before' => ['label' => 'automation.operators.before', 'types' => ['date'], 'arity' => 'one'],
        'after' => ['label' => 'automation.operators.after', 'types' => ['date'], 'arity' => 'one'],
        'within_last_days' => ['label' => 'automation.operators.within_last_days', 'types' => ['date'], 'arity' => 'one'],
        'within_next_days' => ['label' => 'automation.operators.within_next_days', 'types' => ['date'], 'arity' => 'one'],
    ];

    /**
     * @return array<string, array{label: string, types: list<string>, arity: string}>
     */
    public function definitions(): array
    {
        return self::DEFINITIONS;
    }

    public function has(string $operator): bool
    {
        return isset(self::DEFINITIONS[$operator]);
    }

    public function arity(string $operator): string
    {
        return self::DEFINITIONS[$operator]['arity'] ?? throw new InvalidArgumentException("Unknown operator [{$operator}].");
    }

    /**
     * @param  bool  $present  Whether the left-hand value exists at all (distinguishes null from missing).
     */
    public function evaluate(string $operator, mixed $left, mixed $right, bool $present = true, mixed $upper = null): bool
    {
        return match ($operator) {
            'equals' => $present && $this->equals($left, $right),
            'not_equals' => ! ($present && $this->equals($left, $right)),
            'gt' => $this->numbers($left, $right, static fn (float|int $a, float|int $b): bool => $a > $b),
            'gte' => $this->numbers($left, $right, static fn (float|int $a, float|int $b): bool => $a >= $b),
            'lt' => $this->numbers($left, $right, static fn (float|int $a, float|int $b): bool => $a < $b),
            'lte' => $this->numbers($left, $right, static fn (float|int $a, float|int $b): bool => $a <= $b),
            'between' => $this->between($left, $right, $upper),
            'contains' => $this->contains($left, $right),
            'not_contains' => ! $this->contains($left, $right),
            'starts_with' => is_string($left) && is_scalar($right) && $right !== '' && str_starts_with($left, (string) $right),
            'ends_with' => is_string($left) && is_scalar($right) && $right !== '' && str_ends_with($left, (string) $right),
            'in' => $present && $this->in($left, $right),
            'not_in' => ! ($present && $this->in($left, $right)),
            'is_empty' => ! $present || $left === null || $left === '' || $left === [],
            'is_not_empty' => $present && $left !== null && $left !== '' && $left !== [],
            'exists' => $present && $left !== null,
            'not_exists' => ! $present || $left === null,
            'is_true' => $this->boolean($left) === true,
            'is_false' => $this->boolean($left) === false,
            'before' => $this->dates($left, $right, static fn (CarbonImmutable $a, CarbonImmutable $b): bool => $a->lt($b)),
            'after' => $this->dates($left, $right, static fn (CarbonImmutable $a, CarbonImmutable $b): bool => $a->gt($b)),
            'within_last_days' => $this->withinDays($left, $right, -1),
            'within_next_days' => $this->withinDays($left, $right, 1),
            default => throw new InvalidArgumentException("Unknown operator [{$operator}]."),
        };
    }

    private function equals(mixed $left, mixed $right): bool
    {
        if (is_numeric($left) && is_numeric($right)) {
            return (0 + $left) == (0 + $right); // numeric comparison, exact for integers
        }

        if (is_bool($left) || is_bool($right)) {
            return $this->boolean($left) !== null && $this->boolean($left) === $this->boolean($right);
        }

        if (is_scalar($left) && is_scalar($right)) {
            return (string) $left === (string) $right;
        }

        return $left === $right;
    }

    /** @param  callable(float|int, float|int): bool  $compare */
    private function numbers(mixed $left, mixed $right, callable $compare): bool
    {
        if (! is_numeric($left) || ! is_numeric($right)) {
            return false;
        }

        return $compare(0 + $left, 0 + $right);
    }

    private function between(mixed $value, mixed $lower, mixed $upper): bool
    {
        if (is_numeric($value) && is_numeric($lower) && is_numeric($upper)) {
            return (0 + $value) >= (0 + $lower) && (0 + $value) <= (0 + $upper);
        }

        $date = $this->date($value);
        $from = $this->date($lower);
        $to = $this->date($upper);

        return $date !== null && $from !== null && $to !== null && $date->gte($from) && $date->lte($to);
    }

    private function contains(mixed $haystack, mixed $needle): bool
    {
        if (is_string($haystack) && is_scalar($needle) && $needle !== '') {
            return str_contains($haystack, (string) $needle);
        }

        if (is_array($haystack)) {
            foreach ($haystack as $item) {
                if (is_scalar($item) && is_scalar($needle) && $this->equals($item, $needle)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function in(mixed $value, mixed $list): bool
    {
        if (! is_array($list) || ! is_scalar($value)) {
            return false;
        }

        foreach ($list as $candidate) {
            if (is_scalar($candidate) && $this->equals($value, $candidate)) {
                return true;
            }
        }

        return false;
    }

    private function boolean(mixed $value): ?bool
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

    private function date(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable) {
            return null;
        }
    }

    /** @param  callable(CarbonImmutable, CarbonImmutable): bool  $compare */
    private function dates(mixed $left, mixed $right, callable $compare): bool
    {
        $a = $this->date($left);
        $b = $this->date($right);

        return $a !== null && $b !== null && $compare($a, $b);
    }

    private function withinDays(mixed $value, mixed $days, int $direction): bool
    {
        $date = $this->date($value);

        if ($date === null || ! is_numeric($days) || (int) $days < 0) {
            return false;
        }

        $now = CarbonImmutable::now();
        $edge = $now->addDays($direction * (int) $days);

        return $direction < 0 ? $date->between($edge, $now) : $date->between($now, $edge);
    }
}
