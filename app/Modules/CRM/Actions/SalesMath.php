<?php

declare(strict_types=1);

namespace App\Modules\CRM\Actions;

use Brick\Math\BigInteger;
use Brick\Math\RoundingMode;
use Illuminate\Validation\ValidationException;

/** Exact integer arithmetic for quantity-thousandths and minor-unit money. */
final class SalesMath
{
    /** Shared upper bound for manually entered deal, target and contract values. */
    public const int MAX_AMOUNT = 1_000_000_000_000_000;

    /**
     * @return array{gross_amount: int, discount_amount: int, tax_amount: int, total_amount: int}
     */
    public static function line(int $unitPriceAmount, int $quantityMilli, int $discountAmount, int $taxBps): array
    {
        if ($unitPriceAmount < 0 || $unitPriceAmount > 1_000_000_000_000
            || $quantityMilli < 1 || $quantityMilli > 1_000_000
            || $discountAmount < 0 || $taxBps < 0 || $taxBps > 10_000) {
            throw ValidationException::withMessages(['lines' => __('A line contains an invalid amount, quantity, or tax rate.')]);
        }

        $gross = self::multiplyDivide($unitPriceAmount, $quantityMilli, 1000, RoundingMode::HalfUp);

        if ($discountAmount > $gross) {
            throw ValidationException::withMessages(['lines' => __('A line discount cannot exceed its value.')]);
        }

        $net = $gross - $discountAmount;
        $tax = self::multiplyDivide($net, $taxBps, 10_000, RoundingMode::HalfUp);

        return [
            'gross_amount' => $gross,
            'discount_amount' => $discountAmount,
            'tax_amount' => $tax,
            'total_amount' => self::add($net, $tax),
        ];
    }

    /** Add exact integers and fail instead of wrapping, truncating, or converting through float. */
    public static function add(int|string ...$values): int
    {
        return self::sum($values);
    }

    /**
     * Sum exact integer values, including numeric strings returned by MySQL aggregate queries.
     *
     * @param  iterable<mixed>  $values
     */
    public static function sum(iterable $values): int
    {
        $total = BigInteger::zero();

        foreach ($values as $value) {
            if (! is_int($value) && (! is_string($value) || preg_match('/^-?\d+$/D', $value) !== 1)) {
                throw new \InvalidArgumentException('Exact integer sums accept only integers or integer strings.');
            }

            $total = $total->plus($value);
        }

        return $total->toInt();
    }

    /** Normalize a user-supplied non-negative amount to the shared supported bound. */
    public static function boundedAmount(int|string $amount): int
    {
        if (is_string($amount) && preg_match('/^\d+$/D', $amount) !== 1) {
            throw ValidationException::withMessages(['amount' => __('The amount is outside the supported range.')]);
        }

        $value = BigInteger::of($amount);

        if ($value->isNegative() || $value->isGreaterThan(self::MAX_AMOUNT)) {
            throw ValidationException::withMessages(['amount' => __('The amount is outside the supported range.')]);
        }

        return $value->toInt();
    }

    /** Overflow-safe integer multiplication followed by division. */
    public static function multiplyDivide(int $value, int $multiplier, int $divisor, RoundingMode $rounding = RoundingMode::Down): int
    {
        if ($value < 0 || $multiplier < 0 || $divisor < 1) {
            throw new \InvalidArgumentException('Scaled integer inputs must be non-negative and the divisor must be positive.');
        }

        return BigInteger::of($value)
            ->multipliedBy($multiplier)
            ->dividedBy($divisor, $rounding)
            ->toInt();
    }

    /** Overflow-safe ratio expressed in basis points and saturated to a PHP integer. */
    public static function ratioBps(int $part, int $whole): int
    {
        if ($part < 0 || $whole < 0) {
            throw new \InvalidArgumentException('Ratio inputs must be non-negative.');
        }

        if ($whole === 0) {
            return 0;
        }

        $result = BigInteger::of($part)->multipliedBy(10_000)->dividedBy($whole, RoundingMode::Down);

        return $result->isGreaterThan(PHP_INT_MAX) ? PHP_INT_MAX : $result->toInt();
    }
}
