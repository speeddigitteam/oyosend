<?php

declare(strict_types=1);

use App\Modules\Automation\Enums\VersionStatus;
use App\Modules\Automation\Enums\WorkflowStatus;
use App\Modules\Automation\Models\Run;
use App\Modules\Automation\Models\WebhookEndpoint;
use App\Modules\Automation\Models\Workflow;
use App\Modules\Automation\Models\WorkflowVersion;
use Illuminate\Validation\ValidationException;
use Tests\Support\Fixtures\Flow\FlowRecorder;
use Tests\Support\InteractsWithAutomation;

uses(InteractsWithAutomation::class);

beforeEach(function () {
    $this->tenant = $this->flowTenant();
    $this->url = fn (string $path = '') => $this->tenantUrl($this->tenant, '/automation'.$path);
});

it('answers 404 for every page when the workspace is not entitled to automation', function () {
    $plain = $this->createTenant('plain-ws');
    $this->actingAsOwner($plain);

    foreach (['/workflows', '/runs', '/approvals', '/webhooks'] as $path) {
        $this->get($this->tenantUrl($plain, '/automation'.$path))->assertNotFound();
    }
});

it('forbids a member without the permission and lets a holder in', function () {
    [$viewer] = $this->memberWith($this->tenant, ['automation.workflows.view']);
    [$nobody] = $this->memberWith($this->tenant, ['automation.runs.view']);

    $this->actingAsMember($this->tenant, $nobody)->get(($this->url)('/workflows'))->assertForbidden();
    $this->actingAsMember($this->tenant, $viewer)->get(($this->url)('/workflows'))->assertOk();
    $this->actingAsMember($this->tenant, $viewer)->post(($this->url)('/workflows'), ['name' => 'x'])->assertForbidden();
    $this->actingAsMember($this->tenant, $viewer)->get(($this->url)('/runs'))->assertForbidden();
    $this->actingAsMember($this->tenant, $viewer)->get(($this->url)('/webhooks'))->assertForbidden();
});

it('creates a workflow from a template, edits the draft, validates and publishes', function () {
    $this->actingAsOwner($this->tenant);

    $this->post(($this->url)('/workflows'), ['name' => 'Welcome', 'description' => 'Say hi', 'template' => 'welcome_delay'])->assertRedirect();
    $workflow = $this->inTenant($this->tenant, fn () => Workflow::query()->sole());

    expect($workflow->status)->toBe(WorkflowStatus::Draft)->and($workflow->name)->toBe('Welcome');

    $this->get(($this->url)("/workflows/{$workflow->id}/editor"))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Modules/Automation/Pages/Workflows/Editor', false)
            ->where('version.status', 'draft')
            ->where('readOnly', false)
            ->has('version.graph.nodes', 4)
            ->has('catalogue.triggers')->has('catalogue.actions')->has('catalogue.operators')->has('catalogue.nodeTypes', 13)
            ->where('issues', []));

    // Save an edited graph as JSON, the way the builder does, and get the validation back.
    $graph = $this->inTenant($this->tenant, fn () => $workflow->draft()->graph);
    $graph['nodes'][1]['data']['config']['amount'] = 2;
    $this->putJson(($this->url)("/workflows/{$workflow->id}/draft"), ['graph' => $graph])
        ->assertOk()->assertJsonPath('valid', true)->assertJsonPath('version.number', 1);
    expect($this->inTenant($this->tenant, fn () => $workflow->draft()->graph['nodes'][1]['data']['config']['amount']))->toBe(2);

    $this->postJson(($this->url)("/workflows/{$workflow->id}/validate"), ['graph' => ['nodes' => [], 'edges' => []]])
        ->assertOk()->assertJsonPath('valid', false)->assertJsonPath('issues.0.code', 'no_trigger');

    $this->post(($this->url)("/workflows/{$workflow->id}/publish"), ['note' => 'First'])->assertRedirect(($this->url)("/workflows/{$workflow->id}"));

    $workflow = $workflow->refresh();
    $version = $this->inTenant($this->tenant, fn () => WorkflowVersion::query()->findOrFail($workflow->published_version_id));
    expect($workflow->status)->toBe(WorkflowStatus::Active)
        ->and($version->status)->toBe(VersionStatus::Published)
        ->and($version->graph_hash)->toHaveLength(64)
        ->and($version->note)->toBe('First')
        ->and($workflow->trigger_key)->toBe('core.member.joined')
        ->and($this->auditTrail($this->tenant, 'automation.workflow.published'))->toHaveCount(1);
});

it('refuses to publish an invalid draft and reports why', function () {
    $this->actingAsOwner($this->tenant);
    $workflow = $this->makeWorkflow($this->tenant, $this->graphOf([$this->node('end', 'end')], []), publish: false);

    $this->post(($this->url)("/workflows/{$workflow->id}/publish"))->assertSessionHasErrors('graph');

    expect($workflow->refresh()->status)->toBe(WorkflowStatus::Draft);
});

it('makes a published version immutable and versions each publish', function () {
    $workflow = $this->makeWorkflow($this->tenant, $this->simpleGraph());
    $first = $this->inTenant($this->tenant, fn () => WorkflowVersion::query()->findOrFail($workflow->published_version_id));

    expect(fn () => $this->inTenant($this->tenant, fn () => $first->forceFill(['graph' => ['nodes' => [], 'edges' => []]])->save()))
        ->toThrow(LogicException::class);
    expect(fn () => $this->inTenant($this->tenant, fn () => $first->delete()))->toThrow(LogicException::class);

    $this->actingAsOwner($this->tenant);
    // Editing creates a new draft; the live version is untouched until the draft is published.
    $graph = $this->simpleGraph(input: ['label' => 'second']);
    $this->putJson(($this->url)("/workflows/{$workflow->id}/draft"), ['graph' => $graph])->assertOk()->assertJsonPath('version.number', 2);
    expect($this->inTenant($this->tenant, fn () => $first->refresh()->graph['nodes'][1]['data']['config']['input']['label']))->toBe('hello');

    $this->raise($this->tenant);
    expect(FlowRecorder::$calls[0]['input']['label'])->toBe('hello'); // still the published version

    $this->post(($this->url)("/workflows/{$workflow->id}/publish"))->assertRedirect();
    $this->raise($this->tenant, 'two');

    $versions = $this->inTenant($this->tenant, fn () => WorkflowVersion::query()->orderBy('number')->get());
    expect($versions->pluck('status')->map->value->all())->toBe(['archived', 'published'])
        ->and(FlowRecorder::$calls[1]['input']['label'])->toBe('second');

    // An older version can be brought back as a draft and published again.
    $this->post(($this->url)("/workflows/{$workflow->id}/versions/{$first->id}/restore"))->assertRedirect();
    expect($this->inTenant($this->tenant, fn () => $workflow->refresh()->draft()->number))->toBe(3);
});

it('pauses, resumes and archives, and archiving cancels runs in flight', function () {
    $graph = $this->graphOf(
        [$this->eventTrigger(), $this->node('wait', 'delay', ['amount' => 1, 'unit' => 'days']), $this->recordNode('act')],
        [$this->edge('trigger', 'wait'), $this->edge('wait', 'act')],
    );
    $workflow = $this->makeWorkflow($this->tenant, $graph);
    $this->actingAsOwner($this->tenant);

    $this->post(($this->url)("/workflows/{$workflow->id}/pause"))->assertRedirect();
    $this->raise($this->tenant);
    expect($this->runsOf($this->tenant, $workflow))->toBeEmpty();

    $this->post(($this->url)("/workflows/{$workflow->id}/resume"))->assertRedirect();
    $this->raise($this->tenant);
    $run = $this->onlyRun($this->tenant, $workflow);

    $this->post(($this->url)("/workflows/{$workflow->id}/archive"))->assertRedirect(($this->url)('/workflows'));
    $this->travel(2)->days();
    $run = $this->drain($this->tenant, $run);

    expect($workflow->refresh()->status)->toBe(WorkflowStatus::Archived)
        ->and($run->status->value)->toBe('cancelled')
        ->and(FlowRecorder::$calls)->toBeEmpty();

    $this->put(($this->url)("/workflows/{$workflow->id}/draft"), ['graph' => $this->simpleGraph()])->assertSessionHasErrors('graph');
});

it('shows run history with step logs and a snapshot of the executed graph', function () {
    $workflow = $this->makeWorkflow($this->tenant, $this->simpleGraph());
    $this->raise($this->tenant);
    $run = $this->onlyRun($this->tenant, $workflow);
    $this->actingAsOwner($this->tenant);

    $this->get(($this->url)('/runs?status=succeeded'))->assertOk()
        ->assertInertia(fn ($page) => $page->component('Modules/Automation/Pages/Runs/Index', false)->has('runs.data', 1)->where('runs.data.0.workflowName', 'Flow'));

    $this->get(($this->url)("/runs/{$run->id}"))->assertOk()
        ->assertInertia(fn ($page) => $page->component('Modules/Automation/Pages/Runs/Show', false)
            ->where('run.status', 'succeeded')
            ->has('steps', 3)
            ->where('steps.1.nodeId', 'act')->where('steps.1.status', 'succeeded')->has('steps.1.logs', 1)
            ->has('graph.nodes', 3));
});

it('registers webhook endpoints safely and never returns the secret', function () {
    $this->actingAsOwner($this->tenant);

    $this->post(($this->url)('/webhooks'), ['name' => 'Plain', 'url' => 'http://hooks.example.test/in'])->assertSessionHasErrors('url');
    $this->post(($this->url)('/webhooks'), ['name' => 'Local', 'url' => 'https://localhost/in'])->assertSessionHasErrors('url');
    $this->post(($this->url)('/webhooks'), ['name' => 'Ip', 'url' => 'https://10.0.0.1/in'])->assertSessionHasErrors('url');
    $this->post(($this->url)('/webhooks'), ['name' => 'Creds', 'url' => 'https://user:pw@hooks.example.test/in'])->assertSessionHasErrors('url');

    $this->post(($this->url)('/webhooks'), ['name' => 'CRM', 'url' => 'https://hooks.example.test/in', 'secret' => 'a-long-enough-secret'])->assertRedirect();
    $this->post(($this->url)('/webhooks'), ['name' => 'CRM', 'url' => 'https://hooks.example.test/in'])->assertSessionHasErrors('name');

    $this->get(($this->url)('/webhooks'))->assertOk()
        ->assertInertia(fn ($page) => $page->component('Modules/Automation/Pages/Webhooks/Index', false)
            ->has('endpoints', 1)->where('endpoints.0.hasSecret', true)->missing('endpoints.0.secret'));
});

it('refuses to delete an endpoint a live workflow uses', function () {
    $this->actingAsOwner($this->tenant);
    $this->post(($this->url)('/webhooks'), ['name' => 'CRM', 'url' => 'https://hooks.example.test/in'])->assertRedirect();
    $endpoint = $this->inTenant($this->tenant, fn () => WebhookEndpoint::query()->sole());
    $graph = $this->graphOf(
        [$this->eventTrigger(), $this->node('call', 'webhook', ['endpoint_id' => $endpoint->id, 'payload' => []])],
        [$this->edge('trigger', 'call')],
    );
    $this->makeWorkflow($this->tenant, $graph);

    $this->delete(($this->url)("/webhooks/{$endpoint->id}"))->assertSessionHasErrors('endpoint');
    expect($this->inTenant($this->tenant, fn () => WebhookEndpoint::query()->count()))->toBe(1);
});

it('starts a manually triggered workflow by hand only for someone allowed to', function () {
    $graph = $this->graphOf(
        [$this->node('trigger', 'trigger', ['trigger_key' => 'automation.trigger.manual', 'config' => []]), $this->recordNode('act', ['label' => '{{ trigger.who }}'])],
        [$this->edge('trigger', 'act')],
    );
    $workflow = $this->makeWorkflow($this->tenant, $graph);
    [$viewer] = $this->memberWith($this->tenant, ['automation.workflows.view', 'automation.runs.view']);
    [$starter] = $this->memberWith($this->tenant, ['automation.workflows.view', 'automation.runs.view', 'automation.runs.start']);

    $this->actingAsMember($this->tenant, $viewer)->post(($this->url)("/workflows/{$workflow->id}/run"), ['payload' => ['who' => 'me']])->assertForbidden();
    $this->actingAsMember($this->tenant, $starter)->post(($this->url)("/workflows/{$workflow->id}/run"), ['payload' => '{"who":"Rima"}'])->assertRedirect();

    $run = $this->onlyRun($this->tenant, $workflow);
    expect($run->status->value)->toBe('succeeded')->and(FlowRecorder::$calls[0]['input']['label'])->toBe('Rima');
});

it('enforces the plan quotas on active workflows and runs per period', function () {
    $tenant = $this->flowTenant('quota-ws', ['automation', 'test_flow']);
    $plan = $this->createPlan(['modules' => ['automation', 'test_flow'], 'limits' => ['automation.workflows' => 1, 'automation.runs' => 2]]);
    $this->subscribeAndPay($tenant, $plan);

    $first = $this->makeWorkflow($tenant, $this->simpleGraph());
    expect(fn () => $this->makeWorkflow($tenant, $this->simpleGraph(), name: 'Second'))->toThrow(ValidationException::class);

    foreach (['a', 'b', 'c'] as $item) {
        $this->raise($tenant, $item);
    }

    $statuses = collect($this->runsOf($tenant, $first))->map(fn (Run $run) => $run->status->value.':'.($run->error_code ?? ''))->all();
    expect($statuses)->toBe(['succeeded:', 'succeeded:', 'failed:quota_exceeded']);
});

it('is not reachable from another workspace by id', function () {
    $other = $this->flowTenant('flow-intruder');
    $workflow = $this->makeWorkflow($this->tenant, $this->simpleGraph());
    $this->raise($this->tenant);
    $run = $this->onlyRun($this->tenant, $workflow);
    $version = $this->inTenant($this->tenant, fn () => WorkflowVersion::query()->findOrFail($workflow->published_version_id));
    $endpoint = $this->inTenant($this->tenant, fn () => WebhookEndpoint::query()->create(['name' => 'E', 'url' => 'https://hooks.example.test/in']));
    $path = fn (string $p) => '/automation'.$p;

    foreach ([
        ['GET', "/workflows/{$workflow->id}", []],
        ['GET', "/workflows/{$workflow->id}/editor", []],
        ['PUT', "/workflows/{$workflow->id}", ['name' => 'x']],
        ['PUT', "/workflows/{$workflow->id}/draft", ['graph' => $this->simpleGraph()]],
        ['POST', "/workflows/{$workflow->id}/validate", []],
        ['POST', "/workflows/{$workflow->id}/duplicate", []],
        ['POST', "/workflows/{$workflow->id}/versions/{$version->id}/restore", []],
        ['POST', "/workflows/{$workflow->id}/test", []],
        ['GET', "/runs/{$run->id}", []],
        ['POST', "/runs/{$run->id}/retry", []],
        ['PUT', "/webhooks/{$endpoint->id}", ['name' => 'E2', 'url' => 'https://hooks.example.test/in']],
        ['POST', "/workflows/{$workflow->id}/pause", []],
        ['POST', "/workflows/{$workflow->id}/archive", []],
        ['DELETE', "/webhooks/{$endpoint->id}", []],
    ] as [$method, $uri, $data]) {
        $this->assertNotFoundFromOtherTenant($method, $path($uri), $this->tenant, $data, $other);
    }
});
