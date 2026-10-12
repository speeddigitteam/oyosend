<?php

declare(strict_types=1);

namespace App\Core\Automation;

use App\Core\Automation\Contracts\ActionDefinition;
use App\Core\Automation\Contracts\TriggerDefinition;
use App\Core\Entitlements\Contracts\EntitlementChecker;
use App\Core\Tenancy\TenantContext;
use Closure;
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

    /** @var list<Closure(class-string<TriggerDefinition>): void> */
    private array $triggerListeners = [];

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

        foreach ($this->triggerListeners as $listener) {
            $listener($trigger);
        }
    }

    /**
     * Call back for every trigger registered so far and every one registered later. The workflow engine
     * uses it to subscribe to each trigger's domain event, however late its module registers.
     *
     * @param  Closure(class-string<TriggerDefinition>): void  $listener
     */
    public function whenTriggerRegistered(Closure $listener): void
    {
        $this->triggerListeners[] = $listener;

        foreach ($this->triggers as $trigger) {
            $listener($trigger);
        }
    }

    /**
     * Every registered trigger, whatever the active tenant is entitled to. For code that must tell an
     * unknown key from one the tenant lacks (publish validation) or that subscribes to events.
     *
     * @return array<string, class-string<TriggerDefinition>>
     */
    public function registeredTriggers(): array
    {
        return $this->triggers;
    }

    /**
     * @return array<string, class-string<ActionDefinition>>
     */
    public function registeredActions(): array
    {
        return $this->actions;
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
        return array_filter(
            $this->triggers,
            fn (string $trigger): bool => $this->available($trigger::module()),
        );
    }

    /**
     * @return array<string, class-string<ActionDefinition>>
     */
    public function actions(): array
    {
        return array_filter(
            $this->actions,
            fn (string $action): bool => $this->available($action::module()),
        );
    }

    /**
     * @return class-string<TriggerDefinition>|null
     */
    public function trigger(string $key): ?string
    {
        $trigger = $this->triggers[$key] ?? null;

        return $trigger !== null && $this->available($trigger::module()) ? $trigger : null;
    }

    /**
     * @return class-string<ActionDefinition>|null
     */
    public function action(string $key): ?string
    {
        $action = $this->actions[$key] ?? null;

        return $action !== null && $this->available($action::module()) ? $action : null;
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

    /** Central code sees the compiled catalogue; tenant runtime sees only its entitlements. */
    private function available(string $module): bool
    {
        $context = app(TenantContext::class);

        return ! $context->check()
            || app(EntitlementChecker::class)->allows($context->require(), $module);
    }
}
