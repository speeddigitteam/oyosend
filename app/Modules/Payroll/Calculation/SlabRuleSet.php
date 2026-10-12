<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Calculation;

use App\Modules\Payroll\Contracts\PayrollRuleSet;
use Brick\Math\BigInteger;

/**
 * The generic slab (band) evaluator. It knows nothing about any country: every threshold and rate is
 * data entered by the workspace.
 *
 * Let `B` be the basis amount of the period (basic, gross or taxable gross). When the rule is
 * annualized the base is `A = B x periods_per_year`, otherwise `A = B` (and `periods_per_year` counts
 * as 1). Bands are ordered, contiguous half-open ranges `[from, to)`; the last has no upper end.
 *
 *  - `progressive`: each band taxes only the part of `A` inside it at its own rate,
 *    `sum(max(0, min(A, to) - from) x rate)`, and a band's `fixed` amount is added once `A` exceeds its `from`.
 *  - `banded`: the one band with `from <= A < to` applies its rate to the whole of `A`, plus its `fixed` amount.
 *
 * The result is the exact rational `(sum_bps / 10000) / periods_per_year`, rounded half-up once to a
 * minor unit and then clamped to the rule's optional minimum and maximum.
 */
final class SlabRuleSet implements PayrollRuleSet
{
    public const int MAX_SLABS = 50;

    public static function engine(): string
    {
        return 'slab';
    }

    public function label(): string
    {
        return 'Slab table';
    }

    public function validate(array $definition): array
    {
        $slabs = $definition['slabs'] ?? [];

        if (! is_array($slabs) || $slabs === []) {
            return ['slabs' => 'A rule needs at least one slab.'];
        }

        if (count($slabs) > self::MAX_SLABS) {
            return ['slabs' => 'A rule can have at most '.self::MAX_SLABS.' slabs.'];
        }

        $previousTo = 0;
        $last = count($slabs) - 1;

        foreach (array_values($slabs) as $index => $slab) {
            $from = $slab['from'] ?? null;
            $to = $slab['to'] ?? null;
            $rate = $slab['rate_bps'] ?? 0;
            $fixed = $slab['fixed'] ?? 0;

            if (! is_int($from) || $from < 0 || ! is_int($rate) || $rate < 0 || $rate > PayMath::BPS || ! is_int($fixed) || $fixed < 0) {
                return ['slabs' => 'A slab has an invalid amount or rate.'];
            }

            if ($from !== $previousTo) {
                return ['slabs' => $index === 0
                    ? 'The first slab must start at 0.'
                    : 'Slabs must be contiguous: each slab must start where the previous one ends.'];
            }

            if ($to === null && $index !== $last) {
                return ['slabs' => 'Only the last slab can be open-ended.'];
            }

            if ($to !== null && (! is_int($to) || $to <= $from)) {
                return ['slabs' => 'Each slab must end after it starts.'];
            }

            $previousTo = (int) $to;
        }

        return [];
    }

    public function evaluate(RuleDefinition $rule, RuleBasisAmounts $basis): RuleOutcome
    {
        $base = match ($rule->basis) {
            'basic' => $basis->basic,
            'gross' => $basis->gross,
            default => $basis->taxableGross,
        };
        $periods = $rule->annualize ? max(1, $rule->periodsPerYear) : 1;
        $annual = BigInteger::of($base)->multipliedBy($periods);
        $numerator = BigInteger::zero();
        $bands = [];

        foreach ($rule->slabs as $slab) {
            $from = BigInteger::of($slab['from']);
            $to = $slab['to'] === null ? null : BigInteger::of($slab['to']);

            if ($rule->method === 'banded') {
                $inside = $annual->isGreaterThanOrEqualTo($from) && ($to === null || $annual->isLessThan($to));

                if ($inside) {
                    $numerator = $annual->multipliedBy($slab['rate_bps'])->plus(BigInteger::of($slab['fixed'])->multipliedBy(PayMath::BPS));
                    $bands[] = ['position' => $slab['position'], 'portion' => $annual->toInt(), 'rate_bps' => $slab['rate_bps'], 'fixed' => $slab['fixed']];
                    break;
                }

                continue;
            }

            if ($annual->isLessThanOrEqualTo($from)) {
                break;
            }

            $top = $to !== null && $annual->isGreaterThan($to) ? $to : $annual;
            $portion = $top->minus($from);
            $numerator = $numerator
                ->plus($portion->multipliedBy($slab['rate_bps']))
                ->plus(BigInteger::of($slab['fixed'])->multipliedBy(PayMath::BPS));
            $bands[] = ['position' => $slab['position'], 'portion' => $portion->toInt(), 'rate_bps' => $slab['rate_bps'], 'fixed' => $slab['fixed']];
        }

        $amount = $numerator->isZero()
            ? 0
            : PayMath::divide($numerator, BigInteger::of(PayMath::BPS)->multipliedBy($periods));

        if ($rule->minAmount !== null && $amount > 0 && $amount < $rule->minAmount) {
            $amount = $rule->minAmount;
        }

        if ($rule->maxAmount !== null && $amount > $rule->maxAmount) {
            $amount = $rule->maxAmount;
        }

        return new RuleOutcome($amount, [
            'rule_set_id' => $rule->id,
            'version' => $rule->version,
            'method' => $rule->method,
            'basis' => $rule->basis,
            'base' => $base,
            'annualized' => $rule->annualize,
            'periods_per_year' => $periods,
            'bands' => $bands,
        ]);
    }
}
