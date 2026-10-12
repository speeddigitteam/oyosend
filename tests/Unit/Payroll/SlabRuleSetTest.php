<?php

declare(strict_types=1);

use App\Modules\Payroll\Calculation\RuleBasisAmounts;
use App\Modules\Payroll\Calculation\RuleDefinition;
use App\Modules\Payroll\Calculation\RuleOutcome;
use App\Modules\Payroll\Calculation\RuleSetEngines;
use App\Modules\Payroll\Calculation\SlabRuleSet;
use App\Modules\Payroll\Contracts\PayrollRuleSet;

/*
 * EXAMPLE DATA ONLY. These tables are invented to exercise the engine: they are not the law of any
 * country, and Oyosend ships no preloaded rule sets. A workspace enters its own numbers.
 */
$example = static fn (string $method = 'progressive', bool $annualize = false, ?int $min = null, ?int $max = null, ?array $slabs = null): RuleDefinition => new RuleDefinition(
    id: 'rule-1',
    name: 'EXAMPLE slab table',
    code: 'EXAMPLE',
    engine: 'slab',
    category: 'income_tax',
    payer: 'employee',
    basis: 'taxable_gross',
    method: $method,
    annualize: $annualize,
    periodsPerYear: 12,
    minAmount: $min,
    maxAmount: $max,
    slabs: $slabs ?? [
        ['position' => 1, 'from' => 0, 'to' => 100_000, 'rate_bps' => 0, 'fixed' => 0],
        ['position' => 2, 'from' => 100_000, 'to' => 300_000, 'rate_bps' => 1000, 'fixed' => 0],
        ['position' => 3, 'from' => 300_000, 'to' => null, 'rate_bps' => 2500, 'fixed' => 0],
    ],
);
$basis = static fn (int $taxable): RuleBasisAmounts => new RuleBasisAmounts($taxable, $taxable, $taxable, 'BDT', '2026-03-01', '2026-03-31');

it('taxes only the part of the base inside each progressive band', function () use ($example, $basis) {
    $engine = new SlabRuleSet;

    // At or below the first threshold: nothing.
    expect($engine->evaluate($example(), $basis(0))->amount)->toBe(0)
        ->and($engine->evaluate($example(), $basis(100_000))->amount)->toBe(0)
        // One minor unit into the 10 % band: 1 x 10 % = 0.1 -> 0 (half-up of a tenth).
        ->and($engine->evaluate($example(), $basis(100_001))->amount)->toBe(0)
        ->and($engine->evaluate($example(), $basis(100_005))->amount)->toBe(1)
        // Exactly the top of the 10 % band: 200000 x 10 % = 20000.
        ->and($engine->evaluate($example(), $basis(300_000))->amount)->toBe(20_000)
        // Into the open-ended band: 20000 + 100000 x 25 % = 45000.
        ->and($engine->evaluate($example(), $basis(400_000))->amount)->toBe(45_000);
});

it('applies the matching band to the whole base in banded mode, half-open at the boundary', function () use ($example, $basis) {
    $engine = new SlabRuleSet;
    $banded = $example('banded');

    // 99999 is in the 0 % band, 100000 belongs to the next band (the upper bound is exclusive).
    expect($engine->evaluate($banded, $basis(99_999))->amount)->toBe(0)
        ->and($engine->evaluate($banded, $basis(100_000))->amount)->toBe(10_000)
        ->and($engine->evaluate($banded, $basis(299_999))->amount)->toBe(30_000)
        ->and($engine->evaluate($banded, $basis(300_000))->amount)->toBe(75_000);
});

it('annualizes the base and divides back with a single rounding', function () use ($example, $basis) {
    $engine = new SlabRuleSet;
    // Monthly base 30000 -> annual 360000: (200000 x 10 %) + (60000 x 25 %) = 35000 a year, / 12 = 2916.67 -> 2917.
    $outcome = $engine->evaluate($example('progressive', true), $basis(30_000));

    expect($outcome->amount)->toBe(2917)
        ->and($outcome->detail['annualized'])->toBeTrue()
        ->and($outcome->detail['bands'])->toHaveCount(3);
});

it('adds fixed amounts and clamps to the minimum and maximum', function () use ($example, $basis) {
    $engine = new SlabRuleSet;
    $withFixed = $example('banded', false, null, null, [
        ['position' => 1, 'from' => 0, 'to' => 50_000, 'rate_bps' => 0, 'fixed' => 0],
        ['position' => 2, 'from' => 50_000, 'to' => null, 'rate_bps' => 100, 'fixed' => 250],
    ]);

    // 60000 x 1 % + 250 = 850
    expect($engine->evaluate($withFixed, $basis(60_000))->amount)->toBe(850)
        ->and($engine->evaluate($example('progressive', false, 5_000), $basis(110_000))->amount)->toBe(5_000)
        ->and($engine->evaluate($example('progressive', false, null, 10_000), $basis(400_000))->amount)->toBe(10_000)
        // A zero result is not lifted by the minimum.
        ->and($engine->evaluate($example('progressive', false, 5_000), $basis(50_000))->amount)->toBe(0);
});

it('picks the basis the rule names', function () use ($example) {
    $engine = new SlabRuleSet;
    $amounts = new RuleBasisAmounts(basic: 150_000, gross: 250_000, taxableGross: 200_000, currency: 'BDT', from: '2026-03-01', to: '2026-03-31');

    $byBasic = new RuleDefinition(...[...get_object_vars($example()), 'basis' => 'basic']);
    $byGross = new RuleDefinition(...[...get_object_vars($example()), 'basis' => 'gross']);

    expect($engine->evaluate($byBasic, $amounts)->amount)->toBe(5_000)
        ->and($engine->evaluate($byGross, $amounts)->amount)->toBe(15_000)
        ->and($engine->evaluate($example(), $amounts)->amount)->toBe(10_000);
});

it('validates slab tables', function () {
    $engine = new SlabRuleSet;
    $valid = [
        ['from' => 0, 'to' => 100, 'rate_bps' => 0, 'fixed' => 0],
        ['from' => 100, 'to' => null, 'rate_bps' => 500, 'fixed' => 0],
    ];

    expect($engine->validate(['slabs' => $valid]))->toBe([])
        ->and($engine->validate(['slabs' => []]))->toHaveKey('slabs')
        // does not start at zero
        ->and($engine->validate(['slabs' => [['from' => 5, 'to' => null, 'rate_bps' => 0, 'fixed' => 0]]]))->toHaveKey('slabs')
        // gap between bands
        ->and($engine->validate(['slabs' => [['from' => 0, 'to' => 100, 'rate_bps' => 0, 'fixed' => 0], ['from' => 150, 'to' => null, 'rate_bps' => 0, 'fixed' => 0]]]))->toHaveKey('slabs')
        // an open-ended band that is not last
        ->and($engine->validate(['slabs' => [['from' => 0, 'to' => null, 'rate_bps' => 0, 'fixed' => 0], ['from' => 100, 'to' => null, 'rate_bps' => 0, 'fixed' => 0]]]))->toHaveKey('slabs')
        // rate above 100 %
        ->and($engine->validate(['slabs' => [['from' => 0, 'to' => null, 'rate_bps' => 10_001, 'fixed' => 0]]]))->toHaveKey('slabs')
        // end before start
        ->and($engine->validate(['slabs' => [['from' => 0, 'to' => 0, 'rate_bps' => 0, 'fixed' => 0]]]))->toHaveKey('slabs');
});

it('registers further engines behind the PayrollRuleSet contract', function () {
    $engines = new RuleSetEngines;
    expect($engines->keys())->toBe(['slab'])->and($engines->has('flat'))->toBeFalse();

    $engines->register(new class implements PayrollRuleSet
    {
        public static function engine(): string
        {
            return 'flat';
        }

        public function label(): string
        {
            return 'Flat';
        }

        public function validate(array $definition): array
        {
            return [];
        }

        public function evaluate(RuleDefinition $rule, RuleBasisAmounts $basis): RuleOutcome
        {
            return new RuleOutcome(intdiv($basis->gross, 100));
        }
    });

    expect($engines->has('flat'))->toBeTrue()
        ->and($engines->get('flat')->label())->toBe('Flat')
        ->and(fn () => $engines->get('nope'))->toThrow(InvalidArgumentException::class);
});
