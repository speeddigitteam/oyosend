<?php

declare(strict_types=1);

use App\Modules\Automation\Actions\WorkflowManager;
use App\Modules\Automation\Engine\InvalidWorkflow;
use App\Modules\Automation\Graph\GraphValidator;
use Tests\Support\InteractsWithAutomation;

uses(InteractsWithAutomation::class);

beforeEach(function () {
    $this->tenant = $this->flowTenant();

    $this->validate = fn (array $graph, bool $strict = true, $by = null) => $this->inTenant(
        $this->tenant,
        fn () => app(GraphValidator::class)->validate($graph, $by ?? $this->ownerMembership($this->tenant), $strict),
    );
});

it('accepts a well-formed graph', function () {
    $report = ($this->validate)($this->simpleGraph());

    expect($report->hasErrors())->toBeFalse()->and($report->codes())->toBe([]);
});

it('refuses a graph without a trigger, or with two', function () {
    $none = $this->graphOf([$this->node('end', 'end')], []);
    $two = $this->graphOf(
        [$this->eventTrigger(), $this->node('t2', 'trigger', ['trigger_key' => 'automation.trigger.manual', 'config' => []]), $this->node('end', 'end')],
        [$this->edge('trigger', 'end'), $this->edge('t2', 'end')],
    );

    expect(($this->validate)($none)->codes())->toContain('no_trigger')
        ->and(($this->validate)($two)->codes())->toContain('many_triggers');
});

it('refuses unknown trigger and action keys', function () {
    $graph = $this->graphOf(
        [
            $this->node('trigger', 'trigger', ['trigger_key' => 'nope.thing.happened', 'config' => []]),
            $this->node('act', 'action', ['action_key' => 'nope.do.it', 'input' => []]),
        ],
        [$this->edge('trigger', 'act')],
    );

    expect(($this->validate)($graph)->codes())->toContain('unknown_trigger', 'unknown_action');
});

it('refuses a trigger of an app the workspace does not have', function () {
    $this->revokeModules($this->tenant, 'test_flow');

    $report = ($this->validate)($this->simpleGraph());

    // The trigger is registered but the tenant lacks the app: strict (publish) mode makes that an error.
    expect($report->hasErrors())->toBeTrue()->and($report->codes())->toContain('module_not_entitled');
});

it('detects cycles but allows a declared loop-back', function () {
    $cycle = $this->graphOf(
        [$this->eventTrigger(), $this->recordNode('a'), $this->recordNode('b')],
        [$this->edge('trigger', 'a'), $this->edge('a', 'b'), $this->edge('b', 'a')],
    );
    expect(($this->validate)($cycle)->codes())->toContain('cycle');

    $loop = $this->graphOf(
        [
            $this->eventTrigger(),
            $this->node('each', 'loop', ['items' => 'trigger.item_id', 'max_iterations' => 5]),
            $this->recordNode('body'),
            $this->node('end', 'end'),
        ],
        [
            $this->edge('trigger', 'each'),
            $this->edge('each', 'body', 'body'),
            $this->edge('body', 'each', null, true),
            $this->edge('each', 'end', 'done'),
        ],
    );
    expect(($this->validate)($loop)->hasErrors())->toBeFalse();
});

it('refuses nested loops, a loop without a back-edge, and a loop-back from outside the body', function () {
    $nested = $this->graphOf(
        [
            $this->eventTrigger(),
            $this->node('outer', 'loop', ['count' => 2]),
            $this->node('inner', 'loop', ['count' => 2]),
            $this->recordNode('body'),
        ],
        [
            $this->edge('trigger', 'outer'),
            $this->edge('outer', 'inner', 'body'),
            $this->edge('inner', 'body', 'body'),
            $this->edge('body', 'inner', null, true),
            $this->edge('inner', 'outer', 'done', true),
        ],
    );
    expect(($this->validate)($nested)->codes())->toContain('nested_loop');

    $noBack = $this->graphOf(
        [$this->eventTrigger(), $this->node('each', 'loop', ['count' => 2]), $this->recordNode('body')],
        [$this->edge('trigger', 'each'), $this->edge('each', 'body', 'body')],
    );
    expect(($this->validate)($noBack)->codes())->toContain('loop_no_back');

    $outside = $this->graphOf(
        [$this->eventTrigger(), $this->node('each', 'loop', ['count' => 2]), $this->recordNode('body'), $this->recordNode('other')],
        [
            $this->edge('trigger', 'each'), $this->edge('each', 'body', 'body'), $this->edge('body', 'each', null, true),
            $this->edge('each', 'other', 'done'), $this->edge('other', 'each', null, true),
        ],
    );
    expect(($this->validate)($outside)->codes())->toContain('loop_back_outside');
});

it('rejects unreachable nodes, dangling edges, edges into the trigger and bad handles', function () {
    $graph = $this->graphOf(
        [$this->eventTrigger(), $this->recordNode('a'), $this->recordNode('island'), $this->node('check', 'condition', ['match' => 'all', 'rules' => []])],
        [
            $this->edge('trigger', 'a'),
            $this->edge('a', 'trigger'),
            $this->edge('a', 'ghost'),
            $this->edge('a', 'check', 'true'),
        ],
    );

    $codes = ($this->validate)($graph)->codes();

    expect($codes)->toContain('unreachable', 'dangling_edge', 'edge_into_trigger', 'bad_handle');
});

it('validates node configuration', function () {
    $graph = $this->graphOf(
        [
            $this->eventTrigger(),
            $this->node('cond', 'condition', ['match' => 'all', 'rules' => [['field' => 'trigger.amount', 'operator' => 'definitely_not', 'value' => 1]]]),
            $this->node('wait', 'delay', ['amount' => 0, 'unit' => 'days']),
            $this->node('long', 'delay', ['amount' => 400, 'unit' => 'days']),
            $this->node('cron', 'schedule', ['mode' => 'cron', 'cron' => 'not a cron']),
            $this->node('map', 'transform', ['mappings' => [['target' => 'bad name', 'operation' => 'copy', 'source' => 'trigger.amount'], ['target' => 'ok', 'operation' => 'eval', 'value' => '1+1']]]),
            $this->node('ask', 'approval', ['title' => '', 'approver' => ['type' => 'permission', 'permission' => 'no.such.permission']]),
            $this->node('hook', 'webhook', ['endpoint_id' => '01HZZZZZZZZZZZZZZZZZZZZZZZ']),
            $this->node('loop', 'loop', ['count' => 100000]),
            $this->node('end', 'end', ['status' => 'maybe']),
        ],
        [
            $this->edge('trigger', 'cond'), $this->edge('cond', 'wait', 'true'), $this->edge('wait', 'long'), $this->edge('long', 'cron'),
            $this->edge('cron', 'map'), $this->edge('map', 'ask'), $this->edge('ask', 'hook', 'approved'), $this->edge('hook', 'loop'),
            $this->edge('loop', 'end', 'done'),
        ],
    );

    $codes = ($this->validate)($graph)->codes();

    expect($codes)->toContain('unknown_operator', 'bad_delay', 'bad_cron', 'bad_target', 'unknown_operation', 'bad_title', 'bad_approver', 'unknown_endpoint', 'bad_loop_count', 'bad_end');
});

it('refuses references to unknown variables or nodes', function () {
    $graph = $this->graphOf(
        [$this->eventTrigger(), $this->recordNode('act', ['label' => '{{ secrets.api }} {{ steps.nowhere.x }}'])],
        [$this->edge('trigger', 'act')],
    );

    $report = ($this->validate)($graph);

    expect(collect($report->issues())->where('code', 'bad_reference'))->toHaveCount(2);
});

it('checks static action input against the contract but leaves templates for run time', function () {
    $bad = $this->graphOf(
        [$this->eventTrigger(), $this->recordNode('act', ['label' => 12, 'unexpected' => true])],
        [$this->edge('trigger', 'act')],
    );
    $templated = $this->graphOf(
        [$this->eventTrigger(), $this->recordNode('act', ['label' => '{{ trigger.amount }}'])],
        [$this->edge('trigger', 'act')],
    );

    expect(($this->validate)($bad)->codes())->toContain('invalid_input', 'unknown_input')
        ->and(($this->validate)($templated)->hasErrors())->toBeFalse();
});

it('checks the publisher holds every permission, and for all records when the permission is scopable', function () {
    [, $member] = $this->memberWith($this->tenant, ['automation.workflows.manage', 'automation.workflows.publish']);

    $report = ($this->validate)($this->simpleGraph(), true, $member);
    expect($report->hasErrors())->toBeTrue()->and($report->codes())->toContain('permission_missing');

    // The same problem is only a warning while editing.
    $lenient = ($this->validate)($this->simpleGraph(), false, $member);
    expect($lenient->hasErrors())->toBeFalse()->and($lenient->codes())->toContain('permission_missing');

    [, $own] = $this->memberWith(
        $this->tenant,
        ['automation.workflows.publish', 'test_flow.items.view', 'test_flow.items.scoped'],
        ['test_flow.items.scoped' => 'own'],
    );
    $graph = $this->graphOf(
        [$this->eventTrigger(), $this->recordNode('act', ['label' => 'x'], [], 'test_flow.item.scoped')],
        [$this->edge('trigger', 'act')],
    );

    expect(($this->validate)($graph, true, $own)->codes())->toContain('permission_scope');
});

it('refuses to publish an invalid draft and enforces the size limits', function () {
    $tenant = $this->tenant;
    $workflow = $this->makeWorkflow($tenant, $this->graphOf([$this->node('end', 'end')], []), publish: false);

    $this->inTenant($tenant, function () use ($workflow, $tenant) {
        expect(fn () => app(WorkflowManager::class)->publish($workflow, $this->ownerMembership($tenant)))
            ->toThrow(InvalidWorkflow::class);
    });

    $nodes = [$this->eventTrigger()];
    for ($i = 0; $i < 120; $i++) {
        $nodes[] = $this->node('n'.$i, 'end');
    }

    $report = ($this->validate)($this->graphOf($nodes, []));
    expect($report->codes())->toContain('too_many_nodes');
});
