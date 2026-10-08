<?php

declare(strict_types=1);

namespace App\Core\Automation;

use App\Core\Automation\Contracts\ActionDefinition;
use App\Core\Automation\Contracts\TriggerDefinition;
use InvalidArgumentException;

/**
 * Catalogue of the triggers and actions modules make available to workflows.
 *
 * Modules populate it through their manifest from Phase 1 onwards, so the
 * engine built in Phase 10 finds every module already plugged in.
 */
final class AutomationRegistry
{
    /** @var array<string, class-string<TriggerDefinition>> */
    private array $triggers = [];

    /** @var array<string, class-string<ActionDefinition>> */
    private array $actions = [];

    /**
     * @param  class-string<TriggerDefinition>  $trigger
     */
    public function registerTrigger(string $trigger): void
    {
        if (! is_subclass_of($trigger, TriggerDefinition::class)) {
            throw new InvalidArgumentException("[{$trigger}] is not a trigger definition.");
        }

        $this->guardKey($trigger::key(), $trigger::module(), $this->triggers);

        $this->triggers[$trigger::key()] = $trigger;
    }

    /**
     * @param  class-string<ActionDefinition>  $action
     */
    public function registerAction(string $action): void
    {
        if (! is_subclass_of($action, ActionDefinition::class)) {
            throw new InvalidArgumentException("[{$action}] is not an action definition.");
        }

        $this->guardKey($action::key(), $action::module(), $this->actions);

        $this->actions[$action::key()] = $action;
    }

    /**
     * @return array<string, class-string<TriggerDefinition>>
     */
    public function triggers(): array
    {
        return $this->triggers;
    }

    /**
     * @return array<string, class-string<ActionDefinition>>
     */
    public function actions(): array
    {
        return $this->actions;
    }

    /**
     * @return class-string<TriggerDefinition>|null
     */
    public function trigger(string $key): ?string
    {
        return $this->triggers[$key] ?? null;
    }

    /**
     * @return class-string<ActionDefinition>|null
     */
    public function action(string $key): ?string
    {
        return $this->actions[$key] ?? null;
    }

    /**
     * @param  array<string, class-string>  $registered
     */
    private function guardKey(string $key, string $module, array $registered): void
    {
        if (! str_starts_with($key, $module.'.')) {
            throw new InvalidArgumentException("Automation key [{$key}] must be prefixed with its module key [{$module}.].");
        }

        if (isset($registered[$key])) {
            throw new InvalidArgumentException("Automation key [{$key}] is registered twice.");
        }
    }
}
