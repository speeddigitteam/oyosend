<?php

declare(strict_types=1);

namespace App\Modules\Automation\Engine;

/**
 * Evaluates a declarative condition group against a run context.
 *
 * Shape: `{match: all|any, rules: [ {field, operator, value, value2?}, {match, rules: [...]} ]}`.
 * `field` is a context path (`trigger.amount`); `value` / `value2` are literals or `{{ path }}` templates.
 * An empty group is true. Evaluation is pure: it reads the context and reaches no service.
 */
final class ConditionEvaluator
{
    public function __construct(
        private readonly OperatorRegistry $operators,
        private readonly VariableResolver $variables,
    ) {}

    /**
     * @param  array<string, mixed>  $group
     * @param  array<string, mixed>  $context
     */
    public function passes(array $group, array $context): bool
    {
        $rules = is_array($group['rules'] ?? null) ? $group['rules'] : [];
        $any = ($group['match'] ?? 'all') === 'any';

        if ($rules === []) {
            return true;
        }

        foreach ($rules as $rule) {
            if (! is_array($rule)) {
                continue;
            }

            $result = isset($rule['rules']) ? $this->passes($rule, $context) : $this->rule($rule, $context);

            if ($any && $result) {
                return true;
            }

            if (! $any && ! $result) {
                return false;
            }
        }

        return ! $any;
    }

    /**
     * @param  array<string, mixed>  $rule
     * @param  array<string, mixed>  $context
     */
    private function rule(array $rule, array $context): bool
    {
        $operator = (string) ($rule['operator'] ?? '');

        if (! $this->operators->has($operator)) {
            return false;
        }

        $field = (string) ($rule['field'] ?? '');
        [$present, $left] = str_contains($field, '{{')
            ? [true, $this->variables->resolve($field, $context)]
            : $this->variables->lookup($field, $context);

        return $this->operators->evaluate(
            $operator,
            $left,
            $this->variables->resolve($rule['value'] ?? null, $context),
            $present,
            $this->variables->resolve($rule['value2'] ?? null, $context),
        );
    }
}
