<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Actions;

use App\Core\Audit\AuditLogger;
use App\Modules\Payroll\Calculation\RuleDefinition;
use App\Modules\Payroll\Calculation\RuleSetEngines;
use App\Modules\Payroll\Models\RuleSet;
use App\Modules\Payroll\Models\RuleSlab;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Compliance rule sets: tables of thresholds and rates entered by the workspace. Nothing is
 * preloaded and no country's law is encoded here.
 */
final class RuleSetManager
{
    public function __construct(
        private readonly RuleSetEngines $engines,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes  Rule attributes plus `slabs`: list of `{from, to, rate_bps, fixed}`.
     */
    public function save(?RuleSet $ruleSet, array $attributes): RuleSet
    {
        $engine = (string) ($attributes['engine'] ?? 'slab');

        if (! $this->engines->has($engine)) {
            throw ValidationException::withMessages(['engine' => __('This rule engine is not available.')]);
        }

        $slabs = array_values(array_map(static fn (array $slab): array => [
            'from' => (int) $slab['from'],
            'to' => isset($slab['to']) && $slab['to'] !== '' ? (int) $slab['to'] : null,
            'rate_bps' => (int) ($slab['rate_bps'] ?? 0),
            'fixed' => (int) ($slab['fixed'] ?? 0),
        ], (array) ($attributes['slabs'] ?? [])));

        $problems = $this->engines->get($engine)->validate([...$attributes, 'slabs' => $slabs]);

        if ($problems !== []) {
            throw ValidationException::withMessages(array_map(static fn (string $message): string => __($message), $problems));
        }

        $effectiveFrom = (string) $attributes['effective_from'];
        $effectiveTo = $attributes['effective_to'] ?? null;

        if ($effectiveTo !== null && $effectiveTo < $effectiveFrom) {
            throw ValidationException::withMessages(['effective_to' => __('The end date cannot be before the start date.')]);
        }

        $min = isset($attributes['min_amount']) && $attributes['min_amount'] !== '' ? (int) $attributes['min_amount'] : null;
        $max = isset($attributes['max_amount']) && $attributes['max_amount'] !== '' ? (int) $attributes['max_amount'] : null;

        if ($min !== null && $max !== null && $max < $min) {
            throw ValidationException::withMessages(['max_amount' => __('The maximum cannot be below the minimum.')]);
        }

        return DB::transaction(function () use ($ruleSet, $attributes, $engine, $slabs, $effectiveFrom, $effectiveTo, $min, $max): RuleSet {
            $code = strtoupper((string) $attributes['code']);
            $clash = RuleSet::query()->where('code', $code)->where('effective_from', $effectiveFrom)
                ->when($ruleSet !== null, fn ($query) => $query->whereKeyNot($ruleSet?->id))->exists();

            if ($clash) {
                throw ValidationException::withMessages(['code' => __('A rule set with this code and start date already exists.')]);
            }

            $creating = $ruleSet === null;
            $ruleSet ??= new RuleSet;
            $ruleSet->fill([
                'name' => $attributes['name'],
                'code' => $code,
                'country' => isset($attributes['country']) && $attributes['country'] !== '' ? strtoupper((string) $attributes['country']) : null,
                'engine' => $engine,
                'category' => $attributes['category'],
                'payer' => $attributes['payer'],
                'basis' => $attributes['basis'],
                'method' => $attributes['method'],
                'annualize' => (bool) ($attributes['annualize'] ?? false),
                'periods_per_year' => (int) ($attributes['periods_per_year'] ?? 12),
                'min_amount' => $min,
                'max_amount' => $max,
                'employment_types' => ($attributes['employment_types'] ?? []) === [] ? null : array_values((array) $attributes['employment_types']),
                'parameters' => $attributes['parameters'] ?? null,
                'effective_from' => $effectiveFrom,
                'effective_to' => $effectiveTo,
                'is_active' => (bool) ($attributes['is_active'] ?? true),
                'notes' => $attributes['notes'] ?? null,
            ]);

            // The version counts saves; a locked payslip keeps the version it was calculated with.
            $ruleSet->version = $creating ? 1 : $ruleSet->version + 1;

            $ruleSet->save();

            RuleSlab::query()->where('rule_set_id', $ruleSet->id)->delete();

            foreach ($slabs as $index => $slab) {
                RuleSlab::query()->create([
                    'rule_set_id' => $ruleSet->id,
                    'position' => $index + 1,
                    'from_amount' => $slab['from'],
                    'to_amount' => $slab['to'],
                    'rate_bps' => $slab['rate_bps'],
                    'fixed_amount' => $slab['fixed'],
                ]);
            }

            $this->audit->record('payroll.rule_set.saved', $ruleSet, ['version' => $ruleSet->version, 'slabs' => count($slabs)]);

            return $ruleSet->load('slabs');
        });
    }

    /**
     * Active rule sets in force on any day of the period, as plain definitions.
     *
     * @return list<RuleDefinition>
     */
    public function definitionsFor(string $from, string $to): array
    {
        return RuleSet::query()
            ->with('slabs')
            ->where('is_active', true)
            ->where('effective_from', '<=', $to)
            ->where(fn ($query) => $query->whereNull('effective_to')->orWhere('effective_to', '>=', $from))
            ->orderBy('code')
            ->get()
            ->map(fn (RuleSet $rule): RuleDefinition => $this->definition($rule))
            ->all();
    }

    public function definition(RuleSet $rule): RuleDefinition
    {
        return new RuleDefinition(
            id: $rule->id,
            name: $rule->name,
            code: $rule->code,
            engine: $rule->engine,
            category: $rule->category->value,
            payer: $rule->payer->value,
            basis: $rule->basis->value,
            method: $rule->method->value,
            annualize: $rule->annualize,
            periodsPerYear: $rule->periods_per_year,
            minAmount: $rule->min_amount,
            maxAmount: $rule->max_amount,
            slabs: $rule->slabs->map(static fn (RuleSlab $slab): array => [
                'position' => $slab->position,
                'from' => $slab->from_amount,
                'to' => $slab->to_amount,
                'rate_bps' => $slab->rate_bps,
                'fixed' => $slab->fixed_amount,
            ])->all(),
            parameters: $rule->parameters ?? [],
            employmentTypes: $rule->employment_types,
            version: $rule->version,
        );
    }
}
