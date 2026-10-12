<?php

declare(strict_types=1);

namespace App\Modules\Automation\Listeners;

use App\Core\Entitlements\Contracts\EntitlementChecker;
use App\Core\Events\DomainEvent;
use App\Core\Tenancy\Models\Tenant;
use App\Core\Tenancy\TenantContext;
use App\Modules\Automation\Engine\RunScope;
use App\Modules\Automation\Engine\RunStarter;
use App\Modules\Automation\Enums\RunSource;
use App\Modules\Automation\Enums\VersionStatus;
use App\Modules\Automation\Enums\WorkflowStatus;
use App\Modules\Automation\Models\Workflow;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Turns domain events into workflow runs.
 *
 * It subscribes to the event class of every registered trigger (also triggers registered later). For an
 * event it looks only inside the event's own tenant, only at active workflows whose published version uses a
 * trigger bound to that event, and starts one run per matching workflow. The run key is derived from the
 * event itself, so a redelivered event never starts a second run. It must never break the code that raised
 * the event: failures are reported and swallowed.
 */
final class TriggerBridge
{
    public function __construct(
        private readonly TenantContext $tenants,
        private readonly EntitlementChecker $entitlements,
        private readonly RunStarter $starter,
        private readonly RunScope $scope,
        private readonly TriggerMap $map,
    ) {}

    public function handle(DomainEvent $event): void
    {
        try {
            $tenant = $this->tenants->tenant();

            if ($tenant === null || $tenant->getTenantKey() !== $event->tenantId()) {
                $tenant = Tenant::query()->find($event->tenantId());

                if ($tenant === null) {
                    return;
                }

                $this->tenants->run($tenant, fn () => $this->dispatch($event));

                return;
            }

            $this->dispatch($event);
        } catch (Throwable $exception) {
            // Automation must never take down the business action that raised the event.
            report($exception);
        }
    }

    private function dispatch(DomainEvent $event): void
    {
        $keys = $this->map->keysFor($event::class);

        if ($keys === [] || ! $this->entitlements->allows($this->tenants->require(), 'automation')) {
            return;
        }

        $workflows = Workflow::query()
            ->where('status', WorkflowStatus::Active->value)
            ->whereIn('trigger_key', $keys)
            ->with(['publishedVersion', 'actor.user'])
            ->get();

        if ($workflows->isEmpty()) {
            return;
        }

        $payload = $event->payload();
        $depth = $this->scope->runId() === null ? 0 : $this->scope->depth() + 1;
        $fingerprint = sha1($event::class.'|'.$event->occurredAt()->format('Uu').'|'.json_encode($payload));

        foreach ($workflows as $workflow) {
            $version = $workflow->publishedVersion;

            if ($version === null || $version->status !== VersionStatus::Published || ! $this->matches($workflow->trigger_config ?? [], $payload)) {
                continue;
            }

            try {
                $this->starter->start(
                    $workflow,
                    $version,
                    RunSource::Event,
                    'evt:'.$fingerprint,
                    $payload,
                    depth: $depth,
                    parentRunId: $this->scope->runId(),
                );
            } catch (Throwable $exception) {
                Log::error('Automation trigger failed', ['workflow' => $workflow->id, 'error' => $exception::class]);
                report($exception);
            }
        }
    }

    /**
     * Trigger configuration is a plain filter over the event payload: `minimum_x` compares `x` with `>=`,
     * any other key must equal the payload value. An empty configuration matches every event.
     *
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $payload
     */
    private function matches(array $config, array $payload): bool
    {
        foreach ($config as $key => $expected) {
            if ($expected === null || $expected === '') {
                continue;
            }

            $key = (string) $key;

            if (str_starts_with($key, 'minimum_')) {
                $actual = $payload[substr($key, 8)] ?? null;

                if (! is_numeric($actual) || ! is_numeric($expected) || (0 + $actual) < (0 + $expected)) {
                    return false;
                }

                continue;
            }

            if (! array_key_exists($key, $payload) || ! is_scalar($payload[$key]) || (string) $payload[$key] !== (string) $expected) {
                return false;
            }
        }

        return true;
    }
}
