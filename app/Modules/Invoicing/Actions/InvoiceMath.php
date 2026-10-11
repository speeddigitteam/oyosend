<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Actions;

use Brick\Math\BigInteger;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;
use Illuminate\Validation\ValidationException;

/**
 * Exact integer arithmetic for invoices: money in minor units, quantity in thousandths,
 * rates in basis points. Nothing here touches a float.
 */
final class InvoiceMath
{
    public const int MAX_UNIT_PRICE = 1_000_000_000_000;

    public const int MAX_QUANTITY_MILLI = 10_000_000;

    public const int MAX_LINES = 200;

    /** Upper bound for any single manually entered amount (a payment). */
    public const int MAX_AMOUNT = 1_000_000_000_000_000_000;

    /**
     * Gross is rounded half-up to a minor unit; a percentage discount and the tax are each
     * rounded half-up on their own line, so the document total is the sum of its lines.
     *
     * @return array{gross_amount: int, discount_amount: int, net_amount: int, tax_amount: int, line_total_amount: int}
     */
    public static function line(int $unitPriceAmount, int $quantityMilli, ?int $discountAmount, ?int $discountBps, int $taxBps): array
    {
        if ($unitPriceAmount < 0 || $unitPriceAmount > self::MAX_UNIT_PRICE
            || $quantityMilli < 1 || $quantityMilli > self::MAX_QUANTITY_MILLI
            || $taxBps < 0 || $taxBps > 10_000) {
            throw ValidationException::withMessages(['lines' => __('A line contains an invalid amount, quantity, or tax rate.')]);
        }

        if ($discountAmount !== null && $discountAmount > 0 && $discountBps !== null && $discountBps > 0) {
            throw ValidationException::withMessages(['lines' => __('A line can have a discount amount or a discount percentage, not both.')]);
        }

        if (($discountBps !== null && ($discountBps < 0 || $discountBps > 10_000)) || ($discountAmount !== null && $discountAmount < 0)) {
            throw ValidationException::withMessages(['lines' => __('A line contains an invalid discount.')]);
        }

        $gross = self::scale($unitPriceAmount, $quantityMilli, 1000);
        $discount = $discountBps !== null && $discountBps > 0
            ? self::scale($gross, $discountBps, 10_000)
            : (int) ($discountAmount ?? 0);

        if ($discount > $gross) {
            throw ValidationException::withMessages(['lines' => __('A line discount cannot exceed its value.')]);
        }

        $net = $gross - $discount;
        $tax = self::scale($net, $taxBps, 10_000);

        return [
            'gross_amount' => $gross,
            'discount_amount' => $discount,
            'net_amount' => $net,
            'tax_amount' => $tax,
            'line_total_amount' => self::sum([$net, $tax]),
        ];
    }

    /**
     * Sum exact integers (or integer strings from an aggregate query) and fail rather than wrap.
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

        try {
            return $total->toInt();
        } catch (MathException) {
            throw ValidationException::withMessages(['lines' => __('The document total is outside the supported range.')]);
        }
    }

    /** value x multiplier / divisor, rounded half-up, without overflow on the intermediate product. */
    public static function scale(int $value, int $multiplier, int $divisor): int
    {
        if ($value < 0 || $multiplier < 0 || $divisor < 1) {
            throw new \InvalidArgumentException('Scaled integer inputs must be non-negative and the divisor positive.');
        }

        return BigInteger::of($value)->multipliedBy($multiplier)->dividedBy($divisor, RoundingMode::HalfUp)->toInt();
    }

    /** Minutes as hours in thousandths (1.5 h = 1500), rounded half-up. */
    public static function hoursMilli(int $minutes): int
    {
        return self::scale($minutes, 1000, 60);
    }

    /** "1.5", "2", "0.125": thousandths as a plain decimal string, exactly. */
    public static function quantityLabel(int $quantityMilli): string
    {
        $whole = intdiv($quantityMilli, 1000);
        $fraction = rtrim(str_pad((string) ($quantityMilli % 1000), 3, '0', STR_PAD_LEFT), '0');

        return $fraction === '' ? (string) $whole : $whole.'.'.$fraction;
    }
}
