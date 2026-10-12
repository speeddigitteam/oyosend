<?php

declare(strict_types=1);

use App\Core\Billing\UsageMeter;
use App\Modules\Automation\Actions\WorkflowManager;
use App\Modules\Automation\Engine\Redactor;
use App\Modules\Automation\Enums\RunStatus;
use App\Modules\Automation\Enums\StepStatus;
use App\Modules\Automation\Models\Run;
use App\Modules\Automation\Models\RunStep;
use Tests\Support\Fixtures\Flow\FlowRecorder;
use Tests\Support\InteractsWithAutomation;

uses(InteractsWithAutomation::class);

it('runs a published workflow when its trigger event is raised, in the tenant that raised it', function () {
    $tenant = $this->flowTenant();
    $workflow = $this->makeWorkflow($tenant, $this->simpleGraph(input: ['label' => 'hello {{ trigger.item_id }}']));

    $this->raise($tenant, 'item-9', 50);

    $run = $this->onlyRun($tenant, $workflow);
    expect($run->status)->toBe(RunStatus::Succeeded)
        ->and($run->trigger_key)->toBe('test_flow.item.created')
        ->and(FlowRecorder::$calls)->toHaveCount(1)
        ->and(FlowRecorder::$calls[0]['input']['label'])->toBe('hello item-9')
        ->and(FlowRecorder::$calls[0]['key'])->toStartWith('wf:'.$run->id.':');

    $steps = $this->inTenant($tenant, fn () => RunStep::query()->where('run_id', $run->id)->orderBy('sequence')->get());
    expect($steps->pluck('node_id')->all())->toBe(['trigger', 'act', 'end'])
        ->and($steps->every(fn ($s) => $s->status === StepStatus::Succeeded))->toBeTrue();
});

it('does not run a draft, a paused or an archived workflow', function () {
    $tenant = $this->flowTenant();
    $draft = $this->makeWorkflow($tenant, $this->simpleGraph(), publish: false, name: 'Draft');
    $paused = $this->makeWorkflow($tenant, $this->simpleGraph(), name: 'Paused');
    $archived = $this->makeWorkflow($tenant, $this->simpleGraph(), name: 'Archived');

    $this->inTenant($tenant, function () use ($paused, $archived) {
        app(WorkflowManager::class)->pause($paused);
        app(WorkflowManager::class)->archive($archived);
    });

    $this->raise($tenant);

    expect($this->runsOf($tenant, $draft))->toBeEmpty()
        ->and($this->runsOf($tenant, $paused))->toBeEmpty()
        ->and($this->runsOf($tenant, $archived))->toBeEmpty()
        ->and(FlowRecorder::$calls)->toBeEmpty();
});

it('filters on the trigger configuration', function () {
    $tenant = $this->flowTenant();
    $big = $this->makeWorkflow($tenant, $this->simpleGraph(['minimum_amount' => 100]), name: 'Big');
    $special = $this->makeWorkflow($tenant, $this->simpleGraph(['kind' => 'special']), name: 'Special');

    $this->raise($tenant, 'a', 50, 'standard');
    expect($this->runsOf($tenant, $big))->toBeEmpty()->and($this->runsOf($tenant, $special))->toBeEmpty();

    $this->raise($tenant, 'b', 150, 'special');
    expect($this->runsOf($tenant, $big))->toHaveCount(1)->and($this->runsOf($tenant, $special))->toHaveCount(1);
});

it('takes a condition and follows only the matching branch', function () {
    $tenant = $this->flowTenant();
    $graph = $this->graphOf(
        [
            $this->eventTrigger(),
            $this->node('check', 'condition', ['match' => 'all', 'rules' => [['field' => 'trigger.amount', 'operator' => 'gte', 'value' => 100]]]),
            $this->recordNode('big', ['label' => 'big']),
            $this->recordNode('small', ['label' => 'small']),
            $this->node('end', 'end'),
        ],
        [
            $this->edge('trigger', 'check'),
            $this->edge('check', 'big', 'true'),
            $this->edge('check', 'small', 'false'),
            $this->edge('big', 'end'),
            $this->edge('small', 'end'),
        ],
    );
    $this->makeWorkflow($tenant, $graph);

    $this->raise($tenant, 'a', 250);
    $this->raise($tenant, 'b', 5);

    expect(array_map(fn ($call) => $call['input']['label'], FlowRecorder::$calls))->toBe(['big', 'small']);
});

it('handles a transform and feeds its variables to the next step', function () {
    $tenant = $this->flowTenant();
    $graph = $this->graphOf(
        [
            $this->eventTrigger(),
            $this->node('map', 'transform', ['mappings' => [
                ['target' => 'tag', 'operation' => 'uppercase', 'source' => 'trigger.kind'],
                ['target' => 'double', 'operation' => 'multiply', 'values' => ['trigger.amount', 2]],
                ['target' => 'text', 'operation' => 'template', 'value' => '{{ vars.tag }}-{{ vars.double }}'],
            ]]),
            $this->recordNode('act', ['label' => '{{ vars.text }}']),
        ],
        [$this->edge('trigger', 'map'), $this->edge('map', 'act')],
    );
    $this->makeWorkflow($tenant, $graph);

    $this->raise($tenant, 'a', 21, 'standard');

    expect(FlowRecorder::$calls[0]['input']['label'])->toBe('STANDARD-42');
});

it('routes a failing action to its error handler and carries on', function () {
    $tenant = $this->flowTenant();
    $graph = $this->graphOf(
        [
            $this->eventTrigger(),
            $this->recordNode('bad', ['label' => 'x'], ['retry' => ['max_attempts' => 1, 'backoff_seconds' => 1]], 'test_flow.item.reject'),
            $this->node('handler', 'error_handler', ['then' => 'resume']),
            $this->recordNode('after', ['label' => 'recovered {{ error.code }}']),
            $this->node('end', 'end'),
        ],
        [
            $this->edge('trigger', 'bad'),
            $this->edge('bad', 'handler', 'error'),
            $this->edge('handler', 'after'),
            $this->edge('after', 'end'),
        ],
    );
    $workflow = $this->makeWorkflow($tenant, $graph);

    $this->raise($tenant);

    $run = $this->onlyRun($tenant, $workflow);
    expect($run->status)->toBe(RunStatus::Succeeded)
        ->and(FlowRecorder::$calls[0]['input']['label'])->toBe('recovered action_rejected');
});

it('fails the run after the error handler when it is set to fail', function () {
    $tenant = $this->flowTenant();
    $graph = $this->graphOf(
        [
            $this->eventTrigger(),
            $this->recordNode('bad', ['label' => 'x'], [], 'test_flow.item.reject'),
            $this->node('handler', 'error_handler', ['then' => 'fail']),
            $this->recordNode('notify', ['label' => 'tell someone']),
        ],
        [$this->edge('trigger', 'bad'), $this->edge('bad', 'handler', 'error'), $this->edge('handler', 'notify')],
    );
    $workflow = $this->makeWorkflow($tenant, $graph);

    $this->raise($tenant);

    $run = $this->onlyRun($tenant, $workflow);
    expect($run->status)->toBe(RunStatus::Failed)
        ->and($run->error_code)->toBe('action_rejected')
        ->and(FlowRecorder::$calls)->toHaveCount(1); // the notification still ran
});

it('retries a flaky action with backoff and then succeeds, never repeating the effect', function () {
    $tenant = $this->flowTenant();
    FlowRecorder::$failures['flaky'] = 2;
    $graph = $this->graphOf(
        [$this->eventTrigger(), $this->recordNode('act', ['label' => 'x'], ['retry' => ['max_attempts' => 4, 'backoff_seconds' => 60]], 'test_flow.item.flaky')],
        [$this->edge('trigger', 'act')],
    );
    $workflow = $this->makeWorkflow($tenant, $graph);

    $this->raise($tenant);

    $run = $this->onlyRun($tenant, $workflow);
    expect($run->status)->toBe(RunStatus::Waiting)->and($run->resume_at)->not->toBeNull();

    $this->travel(2)->minutes();
    $run = $this->drain($tenant, $run);
    expect($run->status)->toBe(RunStatus::Waiting);

    $this->travel(3)->minutes();
    $run = $this->drain($tenant, $run);

    expect($run->status)->toBe(RunStatus::Succeeded)
        ->and(FlowRecorder::$calls)->toHaveCount(1);

    $step = $this->inTenant($tenant, fn () => RunStep::query()->where('run_id', $run->id)->where('node_id', 'act')->sole());
    expect($step->attempt)->toBe(3)->and($step->logs)->toHaveCount(3);
});

it('redacts secrets in step logs and bounds long strings', function () {
    $tenant = $this->flowTenant();
    $graph = $this->graphOf(
        [$this->eventTrigger(), $this->recordNode('act', ['label' => 'x'])],
        [$this->edge('trigger', 'act')],
    );
    $workflow = $this->makeWorkflow($tenant, $graph);

    $redactor = new Redactor;
    $clean = $redactor->redact(['api_key' => 'abc', 'nested' => ['Authorization' => 'Bearer x', 'name' => str_repeat('a', 5000)]]);

    expect($clean['api_key'])->toBe('[redacted]')
        ->and($clean['nested']['Authorization'])->toBe('[redacted]')
        ->and(strlen($clean['nested']['name']))->toBeLessThan(2100);

    $this->raise($tenant);
    expect($this->onlyRun($tenant, $workflow)->status)->toBe(RunStatus::Succeeded);
});

it('records usage once per run', function () {
    $tenant = $this->flowTenant();
    $this->makeWorkflow($tenant, $this->simpleGraph());

    $this->raise($tenant);
    $this->raise($tenant, 'two');

    $used = $this->inTenant($tenant, fn () => app(UsageMeter::class)->used('automation.runs'));
    expect($used)->toBe(2);
});

it('starts runs only for the workspace that raised the event', function () {
    $acme = $this->flowTenant('flow-acme');
    $globex = $this->flowTenant('flow-globex');
    $acmeFlow = $this->makeWorkflow($acme, $this->simpleGraph(), name: 'Acme');
    $globexFlow = $this->makeWorkflow($globex, $this->simpleGraph(), name: 'Globex');

    $this->raise($acme, 'only-acme');

    expect($this->runsOf($acme, $acmeFlow))->toHaveCount(1)
        ->and($this->runsOf($globex, $globexFlow))->toBeEmpty()
        ->and(FlowRecorder::$calls)->toHaveCount(1);

    // The other workspace's workflow list never contains the run.
    $this->inTenant($globex, fn () => expect(Run::query()->count())->toBe(0));
});

it('starts nothing for a workspace that is no longer entitled to automation', function () {
    $tenant = $this->flowTenant();
    $workflow = $this->makeWorkflow($tenant, $this->simpleGraph());

    $this->revokeModules($tenant, 'automation');
    $this->raise($tenant);

    expect($this->runsOf($tenant, $workflow))->toBeEmpty()->and(FlowRecorder::$calls)->toBeEmpty();
});

it('keeps raising the business event even when a workflow cannot start', function () {
    $tenant = $this->flowTenant();
    $workflow = $this->makeWorkflow($tenant, $this->simpleGraph());

    // A broken trigger configuration stored on the workflow must not break the code that raised the event.
    $this->inTenant($tenant, fn () => $workflow->forceFill(['trigger_config' => ['kind' => ['not', 'a', 'scalar']]])->save());

    $this->raise($tenant);

    expect($this->runsOf($tenant, $workflow))->toBeEmpty();
});
