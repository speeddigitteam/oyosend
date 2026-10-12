<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Core\Access\Models\Role;
use App\Core\Access\RoleManager;
use App\Core\Entitlements\Contracts\EntitlementChecker;
use App\Core\Identity\Models\TenantMembership;
use App\Core\Identity\Models\User;
use App\Core\Modules\ModuleRegistry;
use App\Core\Tenancy\Models\Tenant;
use App\Modules\Automation\Actions\WorkflowManager;
use App\Modules\Automation\Engine\RunExecutor;
use App\Modules\Automation\Models\Run;
use App\Modules\Automation\Models\Workflow;
use Tests\Support\Fixtures\Flow\FlowRecorder;
use Tests\Support\Fixtures\Flow\FlowServiceProvider;
use Tests\Support\Fixtures\Flow\ItemCreated;
use Tests\Support\Fixtures\Flow\RevocableEntitlements;
use Tests\TestCase;

/**
 * Building blocks for workflow engine tests: a workspace entitled to `automation` and the `test_flow`
 * fixture app, small graph builders, and helpers to publish and observe workflows.
 *
 * @mixin TestCase
 */
trait InteractsWithAutomation
{
    protected function flowSetUp(): void
    {
        FlowRecorder::reset();

        if (! $this->app->make(ModuleRegistry::class)->has('test_flow')) {
            $this->registerModule(FlowServiceProvider::class);
        }
    }

    /** @param  list<string>  $modules */
    protected function flowTenant(string $slug = 'flow-ws', array $modules = ['automation', 'test_flow']): Tenant
    {
        $this->flowSetUp();

        $checker = $this->app->make(EntitlementChecker::class);

        if (! $checker instanceof RevocableEntitlements) {
            $checker = new RevocableEntitlements($this->app->make(ModuleRegistry::class));
            $this->app->instance(EntitlementChecker::class, $checker);
            $this->app->forgetScopedInstances();
        }

        $tenant = $this->createTenant($slug, ['timezone' => 'Asia/Dhaka']);
        $checker->grant($tenant, ...$modules);

        return $tenant;
    }

    protected function revokeModules(Tenant $tenant, string ...$modules): void
    {
        $checker = $this->app->make(EntitlementChecker::class);
        assert($checker instanceof RevocableEntitlements);
        $checker->revoke($tenant, ...$modules);
        $this->app->forgetScopedInstances();
    }

    protected function ownerMembership(Tenant $tenant): TenantMembership
    {
        return $this->membershipOf($tenant, $this->ownerOf($tenant));
    }

    /**
     * A member holding exactly the given permissions.
     *
     * @param  list<string>  $permissions
     * @param  array<string, string>  $scopes
     * @return array{0: User, 1: TenantMembership, 2: Role}
     */
    protected function memberWith(Tenant $tenant, array $permissions, array $scopes = []): array
    {
        $owner = $this->ownerOf($tenant);
        $role = $this->inTenant($tenant, fn () => app(RoleManager::class)->create(
            $owner, 'Role '.bin2hex(random_bytes(4)), null, $permissions, $scopes,
        ));
        $user = $this->addMember($tenant, $role);

        return [$user, $this->membershipOf($tenant, $user), $role];
    }

    /**
     * @param  list<string>  $permissions
     * @param  array<string, string>  $scopes
     */
    protected function changeRole(Tenant $tenant, Role $role, array $permissions, array $scopes = []): void
    {
        $owner = $this->ownerOf($tenant);

        $this->inTenant($tenant, function () use ($owner, $role, $permissions, $scopes): void {
            app(RoleManager::class)->update($owner, $role, $role->name, null, $permissions, $scopes);
        });
    }

    // ------------------------------------------------------------------ graph builders

    /** @param  array<string, mixed>  $config */
    protected function node(string $id, string $type, array $config = [], string $label = ''): array
    {
        return ['id' => $id, 'type' => $type, 'position' => ['x' => 0, 'y' => 0], 'data' => ['label' => $label, 'config' => $config]];
    }

    protected function edge(string $from, string $to, ?string $handle = null, bool $loopBack = false): array
    {
        return [
            'id' => 'e_'.$from.'_'.$to.'_'.($handle ?? 'x').($loopBack ? '_b' : ''),
            'source' => $from, 'target' => $to, 'sourceHandle' => $handle, 'data' => ['loopBack' => $loopBack],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $nodes
     * @param  list<array<string, mixed>>  $edges
     * @return array<string, mixed>
     */
    protected function graphOf(array $nodes, array $edges): array
    {
        return ['schema_version' => 1, 'nodes' => $nodes, 'edges' => $edges];
    }

    /**
     * @param  array<string, mixed>  $config
     */
    protected function eventTrigger(array $config = [], string $key = 'test_flow.item.created'): array
    {
        return $this->node('trigger', 'trigger', ['trigger_key' => $key, 'config' => $config]);
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $extra
     */
    protected function recordNode(string $id = 'act', array $input = ['label' => 'x'], array $extra = [], string $action = 'test_flow.item.record'): array
    {
        return $this->node($id, 'action', ['action_key' => $action, 'input' => $input, ...$extra]);
    }

    /** trigger -> record -> end */
    protected function simpleGraph(array $triggerConfig = [], array $input = ['label' => 'hello']): array
    {
        return $this->graphOf(
            [$this->eventTrigger($triggerConfig), $this->recordNode('act', $input), $this->node('end', 'end')],
            [$this->edge('trigger', 'act'), $this->edge('act', 'end')],
        );
    }

    /**
     * Create a workflow from a graph and (by default) publish it as the given member.
     *
     * @param  array<string, mixed>  $graph
     */
    protected function makeWorkflow(Tenant $tenant, array $graph, ?TenantMembership $by = null, bool $publish = true, string $name = 'Flow'): Workflow
    {
        return $this->inTenant($tenant, function () use ($tenant, $graph, $by, $publish, $name): Workflow {
            $by ??= $this->ownerMembership($tenant);
            $manager = app(WorkflowManager::class);
            $workflow = $manager->create($name, null, $by, $graph);

            if ($publish) {
                $manager->publish($workflow, $by);
            }

            return $workflow->refresh();
        });
    }

    protected function raise(Tenant $tenant, string $item = 'item-1', int $amount = 0, string $kind = 'standard'): void
    {
        $this->inTenant($tenant, function () use ($item, $amount, $kind): void {
            ItemCreated::dispatch($item, $amount, $kind);
        });
    }

    /** @return list<Run> */
    protected function runsOf(Tenant $tenant, Workflow $workflow): array
    {
        return $this->inTenant($tenant, fn (): array => Run::query()
            ->where('workflow_id', $workflow->id)->orderBy('created_at')->orderBy('id')->get()->all());
    }

    protected function onlyRun(Tenant $tenant, Workflow $workflow): Run
    {
        $runs = $this->runsOf($tenant, $workflow);
        expect($runs)->toHaveCount(1);

        return $runs[0];
    }

    /** Let the executor run until the run has nothing ready (as the queue would, one persisted step at a time). */
    protected function drain(Tenant $tenant, Run|string $run): Run
    {
        $id = $run instanceof Run ? $run->id : $run;

        return $this->inTenant($tenant, function () use ($id): Run {
            $executor = app(RunExecutor::class);

            for ($i = 0; $i < 500 && $executor->advance($id); $i++) {
                // each call executes one persisted step
            }

            return Run::query()->findOrFail($id);
        });
    }

    /** @return array<string, int> step status => count for a run, keyed by node id. */
    protected function stepStatuses(Tenant $tenant, Run $run): array
    {
        return $this->inTenant($tenant, fn (): array => $run->steps()->orderBy('sequence')->get()
            ->mapWithKeys(fn ($step): array => [$step->node_id.'#'.$step->sequence => $step->status->value])->all());
    }
}
