<?php

declare(strict_types=1);

namespace App\Modules\Talent\Support;

use InvalidArgumentException;

/**
 * The only place that turns ratings into a score. Everything is integer arithmetic:
 *
 *  - a rating is a whole number from 1 to the form's scale maximum;
 *  - a weight is in basis points and the weights of a form add up to exactly 10 000;
 *  - the weighted score is `sum(rating * weight_bp)` divided by 100 and rounded half up, so it is
 *    held in hundredths (4.35 is 435). Nothing is ever averaged by float or invented.
 */
final class ReviewScoring
{
    public const int TOTAL_WEIGHT = 10000;

    /**
     * @param  array<string, int>  $weights  form item id => weight in basis points
     * @param  array<string, int>  $ratings  form item id => rating
     */
    public static function weighted(array $weights, array $ratings, int $scaleMax): int
    {
        if ($weights === [] || array_sum($weights) !== self::TOTAL_WEIGHT) {
            throw new InvalidArgumentException('The weights must add up to exactly 10000 basis points.');
        }

        $sum = 0;

        foreach ($weights as $itemId => $weight) {
            $rating = $ratings[$itemId] ?? null;

            if ($rating === null || $rating < 1 || $rating > $scaleMax) {
                throw new InvalidArgumentException("Every item needs a rating from 1 to {$scaleMax}.");
            }

            $sum += $rating * $weight;
        }

        return intdiv($sum + 50, 100);
    }

    /** Hundredths as text, `435` => `4.35`. Display only. */
    public static function format(int $hundredths): string
    {
        return intdiv($hundredths, 100).'.'.str_pad((string) ($hundredths % 100), 2, '0', STR_PAD_LEFT);
    }
}
