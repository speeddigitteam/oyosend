<?php

declare(strict_types=1);

namespace App\Modules\Projects\Actions;

use Brick\Math\BigInteger;
use Brick\Math\RoundingMode;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use UnexpectedValueException;

/** Overflow-safe arithmetic for project budgets, rates, expenses, and profitability. */
final class ExactMoney
{
    public const int MAX_AMOUNT = 1_000_000_000_000_000;

    public static function amount(int|string $amount, string $field = 'amount'): int
    {
        if (is_string($amount) && preg_match('/^\d+$/D', $amount) !== 1) {
            throw ValidationException::withMessages([$field => __('The amount is outside the supported range.')]);
        }

        $value = BigInteger::of($amount);

        if ($value->isNegative() || $value->isGreaterThan(self::MAX_AMOUNT)) {
            throw ValidationException::withMessages([$field => __('The amount is outside the supported range.')]);
        }

        return $value->toInt();
    }

    /** @param iterable<int|string> $values */
    public static function sum(iterable $values): int
    {
        return BigInteger::of(self::sumString($values))->toInt();
    }

    /**
     * @param  iterable<int|string>  $values
     * @return numeric-string
     */
    public static function sumString(iterable $values): string
    {
        $sum = BigInteger::zero();

        foreach ($values as $value) {
            if (! is_int($value) && preg_match('/^-?\d+$/D', $value) !== 1) {
                throw new InvalidArgumentException('Exact sums accept only integers or integer strings.');
            }

            $sum = $sum->plus($value);
        }

        $result = (string) $sum;

        if (! is_numeric($result)) {
            throw new UnexpectedValueException('The exact integer sum did not produce a numeric string.');
        }

        return $result;
    }

    public static function hourly(int $minutes, int $rateAmount): int
    {
        if ($minutes < 0 || $rateAmount < 0) {
            throw new InvalidArgumentException('Minutes and rates must be non-negative.');
        }

        return BigInteger::of($rateAmount)->multipliedBy($minutes)->dividedBy(60, RoundingMode::HalfUp)->toInt();
    }
}
