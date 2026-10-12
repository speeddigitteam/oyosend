<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Calculation;

use Brick\Math\BigInteger;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;
use InvalidArgumentException;
use RangeException;

/**
 * Exact integer arithmetic for payroll: money in minor units, rates in basis points, time in whole
 * minutes and leave in units. Nothing here touches a float.
 *
 * Rounding rule, used everywhere: a ratio `value x numerator / denominator` of non-negative integers is
 * rounded **half-up** (a fraction of exactly one half rounds away from zero) to a whole minor unit,
 * once, after the multiplication, so the intermediate product never loses precision.
 */
final class PayMath
{
    /** One working day of leave is two units (the HRM contract: a half day is one unit). */
    public const int UNITS_PER_DAY = 2;

    public const int BPS = 10_000;

    /** value x numerator / denominator, rounded half-up, without overflow on the product. */
    public static function scale(int $value, int $numerator, int $denominator): int
    {
        if ($value < 0 || $numerator < 0 || $denominator < 1) {
            throw new InvalidArgumentException('Scaled integer inputs must be non-negative and the denominator positive.');
        }

        try {
            return BigInteger::of($value)->multipliedBy($numerator)->dividedBy($denominator, RoundingMode::HalfUp)->toInt();
        } catch (MathException) {
            throw new RangeException('A payroll amount is outside the supported range.');
        }
    }

    /** Round `$numerator / $denominator` (exact big integers) half-up. */
    public static function divide(BigInteger $numerator, BigInteger $denominator): int
    {
        try {
            return $numerator->dividedBy($denominator, RoundingMode::HalfUp)->toInt();
        } catch (MathException) {
            throw new RangeException('A payroll amount is outside the supported range.');
        }
    }

    /**
     * Sum exact integers (or integer strings from an aggregate query).
     *
     * @param  iterable<mixed>  $values
     */
    public static function sum(iterable $values): int
    {
        $total = BigInteger::zero();

        foreach ($values as $value) {
            $total = $total->plus(self::integer($value));
        }

        try {
            return $total->toInt();
        } catch (MathException) {
            throw new RangeException('A payroll total is outside the supported range.');
        }
    }

    /** An integer from an int or an integer-valued string (a SUM() result); anything else is refused. */
    public static function integer(mixed $value): BigInteger
    {
        if (is_int($value)) {
            return BigInteger::of($value);
        }

        if (is_string($value) && preg_match('/^-?\d+(\.0+)?$/D', $value) === 1) {
            return BigInteger::of(explode('.', $value)[0]);
        }

        throw new InvalidArgumentException('Exact integer sums accept only integers or integer strings.');
    }

    public static function toInt(mixed $value): int
    {
        return self::integer($value)->toInt();
    }
}
