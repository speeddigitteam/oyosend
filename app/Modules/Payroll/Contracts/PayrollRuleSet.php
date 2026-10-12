<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Contracts;

use App\Modules\Payroll\Calculation\RuleBasisAmounts;
use App\Modules\Payroll\Calculation\RuleDefinition;
use App\Modules\Payroll\Calculation\RuleOutcome;

/**
 * A pluggable evaluator for configurable compliance rules (tax, social contribution, levies).
 *
 * The rule's numbers are data (a `payroll_rule_sets` row with its ordered slabs and parameters,
 * entered by the workspace); an engine only knows how to turn that data and a pay basis into one
 * amount. Oyosend ships the generic `slab` engine. Another country or scheme that cannot be expressed
 * as slabs implements this interface and is registered in `RuleSetEngines` by a service provider; it
 * must be pure (no I/O, no clock, no float) so a recalculation is repeatable.
 */
interface PayrollRuleSet
{
    /** The stable key stored in `payroll_rule_sets.engine`: `slab`. */
    public static function engine(): string;

    /** A human label for the settings page. */
    public function label(): string;

    /**
     * Check a rule definition before it is saved.
     *
     * @param  array<string, mixed>  $definition  The rule's attributes plus `slabs` (list of from/to/rate_bps/fixed) and `parameters`.
     * @return array<string, string> Field name => message; empty when the definition is valid.
     */
    public function validate(array $definition): array;

    /** The amount the rule yields for the basis, in minor units of the basis currency. Never negative. */
    public function evaluate(RuleDefinition $rule, RuleBasisAmounts $basis): RuleOutcome;
}
