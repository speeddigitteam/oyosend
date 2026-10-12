<?php

declare(strict_types=1);

namespace App\Modules\Automation\Http\Presenters;

use App\Core\Automation\AutomationRegistry;
use App\Core\Automation\Contracts\ActionDefinition;
use App\Core\Automation\Contracts\TriggerDefinition;
use App\Core\Entitlements\Contracts\EntitlementChecker;
use App\Core\Identity\Enums\MembershipStatus;
use App\Core\Identity\Models\TenantMembership;
use App\Core\Modules\ModuleRegistry;
use App\Core\Tenancy\TenantContext;
use App\Modules\Automation\Engine\ActorGuard;
use App\Modules\Automation\Engine\OperatorRegistry;
use App\Modules\Automation\Engine\Transformer;
use App\Modules\Automation\Engine\VariableResolver;
use App\Modules\Automation\Enums\NodeType;
use App\Modules\Automation\Models\WebhookEndpoint;
use App\Modules\Automation\Support\Limits;
use App\Modules\Automation\Support\Templates;

/**
 * Everything the visual builder needs to offer: which triggers and actions this workspace may use, the node
 * types and their handles, condition operators, mapping operations, hard limits, and the choices the
 * approval and webhook nodes pick from. Built from the live registries: nothing here is hard-coded per app.
 */
final class Catalogue
{
    public function __construct(
        private readonly AutomationRegistry $registry,
        private readonly OperatorRegistry $operators,
        private readonly ModuleRegistry $modules,
        private readonly EntitlementChecker $entitlements,
        private readonly TenantContext $tenants,
        private readonly ActorGuard $guard,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function forEditor(?TenantMembership $actor): array
    {
        $tenant = $this->tenants->require();

        return [
            'triggers' => array_values(array_map(
                fn (string $class): array => $this->definition($class, $actor, 'trigger'),
                $this->registry->triggers(),
            )),
            'actions' => array_values(array_map(
                fn (string $class): array => $this->definition($class, $actor, 'action'),
                $this->registry->actions(),
            )),
            'nodeTypes' => array_map(static fn (NodeType $type): array => [
                'type' => $type->value,
                'label' => 'automation.nodes.'.$type->value,
                'handles' => $type->fixedHandles(),
                'hasDefaultHandle' => ! in_array($type, [NodeType::Condition, NodeType::Approval, NodeType::Loop, NodeType::End, NodeType::Branch], true),
                'canBeTarget' => $type !== NodeType::Trigger,
            ], NodeType::cases()),
            'operators' => array_map(
                static fn (string $key, array $definition): array => ['key' => $key, ...$definition],
                array_keys($this->operators->definitions()),
                array_values($this->operators->definitions()),
            ),
            'mappingOperations' => array_map(
                static fn (string $operation, array $fields): array => ['key' => $operation, 'fields' => $fields],
                array_keys(Transformer::OPERATIONS),
                array_values(Transformer::OPERATIONS),
            ),
            'variableRoots' => VariableResolver::ROOTS,
            'limits' => [
                'maxNodes' => Limits::int('max_nodes'),
                'maxSteps' => Limits::int('max_steps_per_run'),
                'maxLoopIterations' => Limits::int('max_loop_iterations'),
                'maxParallelBranches' => Limits::int('max_parallel_branches'),
                'maxAttempts' => Limits::int('max_attempts'),
                'maxDelayDays' => Limits::int('max_delay_days'),
                'maxDepth' => Limits::int('max_depth'),
                'approvalMaxHours' => Limits::int('approval_max_hours'),
                'approvalDefaultHours' => Limits::int('approval_default_hours'),
            ],
            'webhookEndpoints' => WebhookEndpoint::query()->orderBy('name')->get()
                ->map(static fn (WebhookEndpoint $endpoint): array => [
                    'id' => $endpoint->id, 'name' => $endpoint->name, 'active' => $endpoint->is_active,
                ])->values()->all(),
            'members' => TenantMembership::query()->where('status', MembershipStatus::Active->value)->with('user')->get()
                ->map(static fn (TenantMembership $member): array => ['id' => $member->id, 'name' => $member->user->name])
                ->sortBy('name')->values()->all(),
            'approverPermissions' => collect($this->modules->permissions())
                ->filter(fn ($definition): bool => $this->entitlements->allows($tenant, $definition->module()))
                ->map(static fn ($definition, string $name): array => ['name' => $name, 'module' => $definition->module()])
                ->values()->all(),
            'templates' => array_map(
                static fn (array $template): array => ['key' => $template['key'], 'name' => $template['name'], 'description' => $template['description']],
                Templates::catalogue(),
            ),
            'graphSchema' => [
                'version' => 1,
                'edgeHandles' => 'null for the default output; true/false (condition); approved/rejected/error (approval); body/done (loop); a case id or default (branch); error (action, webhook)',
            ],
        ];
    }

    /**
     * @param  class-string<TriggerDefinition>|class-string<ActionDefinition>  $class
     * @return array<string, mixed>
     */
    private function definition(string $class, ?TenantMembership $actor, string $kind): array
    {
        $key = $class::key();
        $engine = str_starts_with($key, 'automation.trigger.');

        return [
            'key' => $key,
            'label' => $class::label(),
            'module' => $class::module(),
            'kind' => $kind === 'trigger' ? ($engine ? substr($key, strlen('automation.trigger.')) : 'event') : 'action',
            'requiredPermission' => $class::requiredPermission(),
            'inputSchema' => $class::inputSchema(),
            'outputSchema' => $class::outputSchema(),
            'allowed' => $actor !== null && $this->guard->denial($actor, $class::requiredPermission(), $class::module()) === null,
        ];
    }
}
