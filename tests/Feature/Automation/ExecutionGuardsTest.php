<?php

declare(strict_types=1);

use App\Core\Identity\Enums\MembershipStatus;
use App\Modules\Automation\Actions\WorkflowManager;
use App\Modules\Automation\Enums\RunStatus;
use App\Modules\Automation\Enums\StepStatus;
use App\Modules\Automation\Models\RunStep;
use App\Modules\Automation\Models\WorkflowVersion;
use Tests\Support\Fixtures\Flow\FlowRecorder;
use Tests\Support\InteractsWithAutomation;

uses(InteractsWithAutomation::class);

beforeEach(function () {
    // A workflow that waits a day between two recording steps, so a test can change the world in the middle.
    $this->delayedGraph = fn (): array => $this->graphOf(
        [
            $this->eventTrigger(),
            $this->recordNode('first', ['label' => 'first']),
            $this->node('wait', 'delay', ['amount' => 1, 'unit' => 'days']),
            $this->recordNode('second', ['label' => 'second']),
            $this->node('end', 'end'),
        ],
        [$this->edge('trigger', 'first'), $this->edge('first', 'wait'), $this->edge('wait', 'second'), $this->edge('second', 'end')],
    );
});

it('stops a waiting run when the app its next step needs is no longer entitled', function () {
    $tenant = $this->flowTenant();
    $workflow = $this->makeWorkflow($tenant, ($this->delayedGraph)());

    $this->raise($tenant);
    $run = $this->onlyRun($tenant, $workflow);
    expect($run->status)->toBe(RunStatus::Waiting)->and(FlowRecorder::$calls)->toHaveCount(1);

    $this->revokeModules($tenant, 'test_flow');
    $this->travel(2)->days();
    $run = $this->drain($tenant, $run);

    expect($run->status)->toBe(RunStatus::Failed)
        ->and($run->error_code)->toBe('module_not_entitled')
        ->and(FlowRecorder::$calls)->toHaveCount(1); // the second step never ran
});

it('stops a run when the automation app itself is no longer entitled', function () {
    $tenant = $this->flowTenant();
    $workflow = $this->makeWorkflow($tenant, ($this->delayedGraph)());

    $this->raise($tenant);
    $run = $this->onlyRun($tenant, $workflow);

    $this->revokeModules($tenant, 'automation');
    $this->travel(2)->days();
    $run = $this->drain($tenant, $run);

    expect($run->status)->toBe(RunStatus::Failed)->and($run->error_code)->toBe('automation_not_entitled')
        ->and(FlowRecorder::$calls)->toHaveCount(1);
});

it('stops a run at its next step when the workflow actor loses a permission', function () {
    $tenant = $this->flowTenant();
    [, $member, $role] = $this->memberWith($tenant, [
        'automation.workflows.manage', 'automation.workflows.publish', 'test_flow.items.view', 'test_flow.items.manage',
    ]);
    $workflow = $this->makeWorkflow($tenant, ($this->delayedGraph)(), $member);

    $this->raise($tenant);
    $run = $this->onlyRun($tenant, $workflow);
    expect($run->status)->toBe(RunStatus::Waiting)->and(FlowRecorder::$calls)->toHaveCount(1);

    // The permission the second step needs is taken away while the run is waiting.
    $this->changeRole($tenant, $role, ['automation.workflows.manage', 'automation.workflows.publish', 'test_flow.items.view']);
    $this->travel(2)->days();
    $run = $this->drain($tenant, $run);

    expect($run->status)->toBe(RunStatus::Failed)
        ->and($run->error_code)->toBe('permission_missing')
        ->and(FlowRecorder::$calls)->toHaveCount(1);

    $step = $this->inTenant($tenant, fn () => RunStep::query()->where('run_id', $run->id)->where('node_id', 'second')->sole());
    expect($step->status)->toBe(StepStatus::Failed)->and($step->attempt)->toBe(1);
});

it('does not route an authorization failure to the error handler', function () {
    $tenant = $this->flowTenant();
    [, $member, $role] = $this->memberWith($tenant, [
        'automation.workflows.manage', 'automation.workflows.publish', 'test_flow.items.view', 'test_flow.items.manage',
    ]);
    $graph = $this->graphOf(
        [
            $this->eventTrigger(),
            $this->node('wait', 'delay', ['amount' => 1, 'unit' => 'hours']),
            $this->recordNode('act'),
            $this->node('handler', 'error_handler', ['then' => 'resume']),
            $this->recordNode('after', ['label' => 'should not run']),
        ],
        [$this->edge('trigger', 'wait'), $this->edge('wait', 'act'), $this->edge('act', 'handler', 'error'), $this->edge('handler', 'after')],
    );
    $workflow = $this->makeWorkflow($tenant, $graph, $member);

    $this->raise($tenant);
    $this->changeRole($tenant, $role, ['automation.workflows.manage', 'automation.workflows.publish', 'test_flow.items.view']);
    $this->travel(2)->hours();
    $run = $this->drain($tenant, $this->onlyRun($tenant, $workflow));

    expect($run->status)->toBe(RunStatus::Failed)->and(FlowRecorder::$calls)->toBeEmpty();
});

it('stops when the member the workflow runs as is removed from the workspace', function () {
    $tenant = $this->flowTenant();
    [, $member] = $this->memberWith($tenant, [
        'automation.workflows.manage', 'automation.workflows.publish', 'test_flow.items.view', 'test_flow.items.manage',
    ]);
    $workflow = $this->makeWorkflow($tenant, ($this->delayedGraph)(), $member);

    $this->raise($tenant);
    $run = $this->onlyRun($tenant, $workflow);

    $this->inTenant($tenant, fn () => $member->forceFill(['status' => MembershipStatus::Suspended])->save());
    $this->travel(2)->days();
    $run = $this->drain($tenant, $run);

    expect($run->status)->toBe(RunStatus::Failed)->and($run->error_code)->toBe('actor_inactive')
        ->and(FlowRecorder::$calls)->toHaveCount(1);
});

it('requires the actor to keep the publish permission for any step to run', function () {
    $tenant = $this->flowTenant();
    [, $member, $role] = $this->memberWith($tenant, [
        'automation.workflows.manage', 'automation.workflows.publish', 'test_flow.items.view', 'test_flow.items.manage',
    ]);
    $workflow = $this->makeWorkflow($tenant, $this->simpleGraph(), $member);

    $this->changeRole($tenant, $role, ['test_flow.items.view', 'test_flow.items.manage']);
    $this->raise($tenant);

    $run = $this->onlyRun($tenant, $workflow);
    expect($run->status)->toBe(RunStatus::Failed)->and($run->error_code)->toBe('permission_missing')
        ->and(FlowRecorder::$calls)->toBeEmpty();
});

it('runs as the member who published, and a later publisher takes over', function () {
    $tenant = $this->flowTenant();
    [, $first] = $this->memberWith($tenant, ['automation.workflows.manage', 'automation.workflows.publish', 'test_flow.items.view', 'test_flow.items.manage']);
    $owner = $this->ownerMembership($tenant);
    $workflow = $this->makeWorkflow($tenant, $this->simpleGraph(), $first);

    expect($workflow->actor_membership_id)->toBe($first->id);

    $this->inTenant($tenant, function () use ($workflow, $owner) {
        $manager = app(WorkflowManager::class);
        $manager->saveDraft($workflow, WorkflowVersion::query()->findOrFail($workflow->published_version_id)->graph, $owner);
        $manager->publish($workflow, $owner);
    });

    expect($workflow->refresh()->actor_membership_id)->toBe($owner->id);
});

it('denies a scopable permission held for own records only', function () {
    $tenant = $this->flowTenant();
    [, $member] = $this->memberWith(
        $tenant,
        ['automation.workflows.publish', 'test_flow.items.view', 'test_flow.items.scoped'],
        ['test_flow.items.scoped' => 'all'],
    );
    $graph = $this->graphOf(
        [$this->eventTrigger(), $this->recordNode('act', ['label' => 'x'], [], 'test_flow.item.scoped')],
        [$this->edge('trigger', 'act')],
    );

    // Held for all records: publishes and runs.
    $workflow = $this->makeWorkflow($tenant, $graph, $member);
    $this->raise($tenant);

    expect($this->onlyRun($tenant, $workflow)->status)->toBe(RunStatus::Succeeded);
});
