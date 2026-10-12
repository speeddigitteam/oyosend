<?php

declare(strict_types=1);

use App\Modules\Payroll\Calculation\PayMath;

it('rounds a ratio half-up, once, to a whole minor unit', function () {
    // 1 / 2 = 0.5 -> 1 (exactly one half rounds up)
    expect(PayMath::scale(1, 1, 2))->toBe(1)
        // 1 / 3 = 0.333 -> 0, 2 / 3 = 0.667 -> 1
        ->and(PayMath::scale(1, 1, 3))->toBe(0)
        ->and(PayMath::scale(2, 1, 3))->toBe(1)
        // 70000 x 60 x 15000 / (7 x 480 x 10000) = 1875 exactly
        ->and(PayMath::scale(70000, 60 * 15000, 7 * 480 * 10000))->toBe(1875)
        // 103000 x 2 / 14 = 14714.2857 -> 14714
        ->and(PayMath::scale(103000, 2, 14))->toBe(14714)
        // 25447 x 1000 / 10000 = 2544.7 -> 2545
        ->and(PayMath::scale(25447, 1000, 10000))->toBe(2545)
        // 2 x 25 / 100 = 0.5 -> 1, 1 x 25 / 100 = 0.25 -> 0
        ->and(PayMath::scale(2, 25, 100))->toBe(1)
        ->and(PayMath::scale(1, 25, 100))->toBe(0);
});

it('does not overflow on a large intermediate product and refuses a result outside 64 bits', function () {
    // 9e17 x 10000 / 10000 would overflow a 64-bit product but not the big-integer math.
    expect(PayMath::scale(900_000_000_000_000_000, 10_000, 10_000))->toBe(900_000_000_000_000_000)
        ->and(fn () => PayMath::scale(PHP_INT_MAX, 10, 1))->toThrow(RangeException::class);
});

it('refuses negative input and zero denominators', function () {
    expect(fn () => PayMath::scale(-1, 1, 1))->toThrow(InvalidArgumentException::class)
        ->and(fn () => PayMath::scale(1, -1, 1))->toThrow(InvalidArgumentException::class)
        ->and(fn () => PayMath::scale(1, 1, 0))->toThrow(InvalidArgumentException::class);
});

it('sums integers and integer strings exactly and refuses anything else', function () {
    expect(PayMath::sum([1, '2', '3.0', -1]))->toBe(5)
        ->and(PayMath::sum([PHP_INT_MAX, -1]))->toBe(PHP_INT_MAX - 1)
        ->and(fn () => PayMath::sum([1.5]))->toThrow(InvalidArgumentException::class)
        ->and(fn () => PayMath::sum(['1.5']))->toThrow(InvalidArgumentException::class)
        ->and(fn () => PayMath::sum([PHP_INT_MAX, 1]))->toThrow(RangeException::class);
});
