<?php

declare(strict_types=1);

use App\Modules\CRM\Actions\SalesMath;
use Brick\Math\Exception\IntegerOverflowException;
use Illuminate\Validation\ValidationException;

it('keeps maximum supported line arithmetic exact without integer overflow', function () {
    expect(SalesMath::line(1_000_000_000_000, 1_000_000, 0, 10_000))->toBe([
        'gross_amount' => 1_000_000_000_000_000,
        'discount_amount' => 0,
        'tax_amount' => 1_000_000_000_000_000,
        'total_amount' => 2_000_000_000_000_000,
    ]);
});

it('calculates large basis-point ratios without converting through floating point', function () {
    expect(SalesMath::ratioBps(1_000_000_000_000_000, 500_000_000_000_000))->toBe(20_000)
        ->and(SalesMath::ratioBps(1_000_000_000_000_000, 1))->toBe(PHP_INT_MAX);
});

it('adds and sums exact integers without native overflow or numeric-string coercion', function () {
    expect(SalesMath::add('9007199254740993', 7))->toBe(9_007_199_254_741_000)
        ->and(SalesMath::sum(['4000000000000000', 5_000_000_000_000_000]))->toBe(9_000_000_000_000_000);

    expect(fn () => SalesMath::add(PHP_INT_MAX, 1))->toThrow(IntegerOverflowException::class)
        ->and(fn () => SalesMath::sum([1.5]))->toThrow(InvalidArgumentException::class);
});

it('normalizes the shared supported amount bound', function () {
    expect(SalesMath::boundedAmount((string) SalesMath::MAX_AMOUNT))->toBe(SalesMath::MAX_AMOUNT);

    expect(fn () => SalesMath::boundedAmount((string) (SalesMath::MAX_AMOUNT + 1)))
        ->toThrow(ValidationException::class);
});
