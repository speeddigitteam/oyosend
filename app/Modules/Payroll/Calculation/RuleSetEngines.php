<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Calculation;

use App\Modules\Payroll\Contracts\PayrollRuleSet;
use InvalidArgumentException;

/**
 * The registry of rule engines. `slab` is built in; a provider registers further engines
 * (another country's scheme) with {@see register()}.
 */
final class RuleSetEngines
{
    /** @var array<string, PayrollRuleSet> */
    private array $engines = [];

    public function __construct()
    {
        $this->register(new SlabRuleSet);
    }

    public function register(PayrollRuleSet $engine): void
    {
        $this->engines[$engine::engine()] = $engine;
    }

    public function has(string $key): bool
    {
        return isset($this->engines[$key]);
    }

    public function get(string $key): PayrollRuleSet
    {
        return $this->engines[$key] ?? throw new InvalidArgumentException("Unknown payroll rule engine [{$key}].");
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_keys($this->engines);
    }

    /** @return array<string, string> key => label */
    public function labels(): array
    {
        return array_map(static fn (PayrollRuleSet $engine): string => $engine->label(), $this->engines);
    }
}
