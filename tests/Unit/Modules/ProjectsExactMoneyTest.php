<?php

declare(strict_types=1);

use App\Modules\Projects\Actions\ExactMoney;
use Brick\Math\Exception\IntegerOverflowException;
use Illuminate\Validation\ValidationException;

it('normalizes project money without accepting decimals, negatives, or oversized input', function () {
    expect(ExactMoney::amount(0))->toBe(0)
        ->and(ExactMoney::amount('1000000000000000'))->toBe(1_000_000_000_000_000);

    foreach ([-1, '1.50', '1e3', ' 100', '100 ', '1000000000000001'] as $invalid) {
        expect(fn () => ExactMoney::amount($invalid, 'budget_amount'))
            ->toThrow(ValidationException::class);
    }
});

it('sums database integer strings exactly and fails rather than overflowing', function () {
    expect(ExactMoney::sum([1, '9007199254740993', 6]))->toBe(9_007_199_254_741_000)
        ->and(ExactMoney::sumString([PHP_INT_MAX, 1]))->toBe('9223372036854775808')
        ->and(fn () => ExactMoney::sum([1, '2.5']))->toThrow(InvalidArgumentException::class)
        ->and(fn () => ExactMoney::sum([PHP_INT_MAX, 1]))->toThrow(IntegerOverflowException::class);
});

it('calculates hourly project values with deterministic half-up rounding', function () {
    expect(ExactMoney::hourly(30, 101))->toBe(51)
        ->and(ExactMoney::hourly(90, 10_000))->toBe(15_000)
        ->and(ExactMoney::hourly(0, PHP_INT_MAX))->toBe(0)
        ->and(fn () => ExactMoney::hourly(-1, 100))->toThrow(InvalidArgumentException::class)
        ->and(fn () => ExactMoney::hourly(1, -100))->toThrow(InvalidArgumentException::class);
});
