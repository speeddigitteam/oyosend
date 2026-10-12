<?php

declare(strict_types=1);

namespace App\Modules\Automation\Listeners;

use App\Core\Automation\AutomationRegistry;
use Illuminate\Contracts\Events\Dispatcher;

/**
 * Which trigger keys each domain event class starts. Bound as a singleton of the application, it also
 * subscribes the {@see TriggerBridge} to every event class exactly once - for triggers registered at boot
 * and for those a module registers later.
 */
final class TriggerMap
{
    /** @var array<string, list<string>> */
    private array $triggers = [];

    public function __construct(private readonly Dispatcher $events) {}

    public function follow(AutomationRegistry $registry): void
    {
        $registry->whenTriggerRegistered(function (string $trigger): void {
            $event = $trigger::event();

            if ($event === null) {
                return;
            }

            if (! isset($this->triggers[$event])) {
                $this->triggers[$event] = [];
                $this->events->listen($event, [TriggerBridge::class, 'handle']);
            }

            $this->triggers[$event] = array_values(array_unique([...$this->triggers[$event], $trigger::key()]));
        });
    }

    /** @return list<string> */
    public function keysFor(string $event): array
    {
        return $this->triggers[$event] ?? [];
    }
}
