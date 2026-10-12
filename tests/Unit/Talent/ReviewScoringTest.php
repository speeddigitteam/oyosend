<?php

declare(strict_types=1);

use App\Modules\Talent\Support\GoalProgress;
use App\Modules\Talent\Support\ReviewScoring;

it('computes the weighted score in exact integer hundredths', function (array $weights, array $ratings, int $expected) {
    expect(ReviewScoring::weighted($weights, $ratings, 5))->toBe($expected);
})->with([
    'two items 60/40' => [['a' => 6000, 'b' => 4000], ['a' => 4, 'b' => 5], 440],
    'thirds that add to 10000' => [['a' => 3333, 'b' => 3333, 'c' => 3334], ['a' => 3, 'b' => 4, 'c' => 5], 400],
    'rounds half up' => [['a' => 5050, 'b' => 4950], ['a' => 1, 'b' => 2], 150],
    'rounds down below half' => [['a' => 3333, 'b' => 3333, 'c' => 3334], ['a' => 1, 'b' => 1, 'c' => 2], 133],
    'single item' => [['a' => 10000], ['a' => 5], 500],
    'all ones' => [['a' => 2500, 'b' => 2500, 'c' => 2500, 'd' => 2500], ['a' => 1, 'b' => 1, 'c' => 1, 'd' => 1], 100],
]);

it('refuses weights that do not add up and ratings outside the scale or missing', function () {
    expect(fn () => ReviewScoring::weighted(['a' => 5000, 'b' => 4000], ['a' => 3, 'b' => 3], 5))->toThrow(InvalidArgumentException::class)
        ->and(fn () => ReviewScoring::weighted([], [], 5))->toThrow(InvalidArgumentException::class)
        ->and(fn () => ReviewScoring::weighted(['a' => 10000], ['a' => 6], 5))->toThrow(InvalidArgumentException::class)
        ->and(fn () => ReviewScoring::weighted(['a' => 10000], ['a' => 0], 5))->toThrow(InvalidArgumentException::class)
        ->and(fn () => ReviewScoring::weighted(['a' => 5000, 'b' => 5000], ['a' => 3], 5))->toThrow(InvalidArgumentException::class);
});

it('formats hundredths for display only', function () {
    expect(ReviewScoring::format(435))->toBe('4.35')
        ->and(ReviewScoring::format(400))->toBe('4.00')
        ->and(ReviewScoring::format(105))->toBe('1.05');
});

it('measures goal progress in basis points, clamped, for targets above and below the start', function () {
    expect(GoalProgress::basisPoints(0, 30, 10))->toBe(3333)
        ->and(GoalProgress::basisPoints(0, 30, 45))->toBe(10000)
        ->and(GoalProgress::basisPoints(0, 30, -5))->toBe(0)
        ->and(GoalProgress::basisPoints(20, 5, 12))->toBe(5333)
        ->and(GoalProgress::basisPoints(100, 200, 100))->toBe(0)
        ->and(GoalProgress::reached(0, 30, 30))->toBeTrue()
        ->and(GoalProgress::reached(0, 30, 29))->toBeFalse()
        ->and(GoalProgress::reached(20, 5, 5))->toBeTrue()
        ->and(GoalProgress::reached(20, 5, 6))->toBeFalse();
});
