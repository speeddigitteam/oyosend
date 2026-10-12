<?php

declare(strict_types=1);

use App\Core\Billing\UsageMeter;
use App\Modules\Automation\Actions\RunManager;
use App\Modules\Automation\Actions\WorkflowManager;
use App\Modules\Automation\Engine\InvalidWorkflow;
use App\Modules\Automation\Engine\Outbound\HostResolver;
use App\Modules\Automation\Enums\RunMode;
use App\Modules\Automation\Enums\RunStatus;
use App\Modules\Automation\Models\Approval;
use App\Modules\Automation\Models\Run;
use App\Modules\Automation\Models\WebhookEndpoint;
use Illuminate\Support\Facades\Http;
use Tests\Support\Fixtures\Flow\FlowRecorder;
use Tests\Support\InteractsWithAutomation;

uses(InteractsWithAutomation::class);

it('executes a draft without performing any action, webhook, approval or wait', function () {
    $tenant = $this->flowTenant();
    Http::fake();
    $this->app->bind(HostResolver::class, fn () => new class extends HostResolver
    {
        public function resolve(string $host): array
        {
            return ['93.184.216.34'];
        }
    });
    $endpoint = $this->inTenant($tenant, fn () => WebhookEndpoint::query()->create(['name' => 'Hook', 'url' => 'https://hooks.example.test/in', 'is_active' => true]));

    $graph = $this->graphOf(
        [
            $this->node('trigger', 'trigger', ['trigger_key' => 'automation.trigger.manual', 'config' => []]),
            $this->node('check', 'condition', ['match' => 'all', 'rules' => [['field' => 'trigger.amount', 'operator' => 'gt', 'value' => 10]]]),
            $this->recordNode('act', ['label' => 'real effect']),
            $this->node('wait', 'delay', ['amount' => 5, 'unit' => 'days']),
            $this->node('ask', 'approval', ['title' => 'Sure?', 'approver' => ['type' => 'permission', 'permission' => 'automation.approvals.decide']]),
            $this->node('call', 'webhook', ['endpoint_id' => $endpoint->id, 'payload' => ['x' => 1]]),
            $this->node('end', 'end'),
            $this->node('small', 'end'),
        ],
        [
            $this->edge('trigger', 'check'),
            $this->edge('check', 'act', 'true'), $this->edge('check', 'small', 'false'),
            $this->edge('act', 'wait'), $this->edge('wait', 'ask'),
            $this->edge('ask', 'call', 'approved'), $this->edge('call', 'end'),
        ],
    );
    // Never published: a test run works on the draft.
    $workflow = $this->makeWorkflow($tenant, $graph, publish: false);

    $run = $this->inTenant($tenant, fn () => app(RunManager::class)->test($workflow, $this->ownerMembership($tenant), ['amount' => 50]));
    $run = $this->inTenant($tenant, fn () => Run::query()->findOrFail($run->id));

    expect($run->mode)->toBe(RunMode::DryRun)
        ->and($run->status)->toBe(RunStatus::Succeeded)
        ->and(FlowRecorder::$calls)->toBeEmpty()
        ->and($this->inTenant($tenant, fn () => Approval::query()->count()))->toBe(0)
        ->and($this->inTenant($tenant, fn () => app(UsageMeter::class)->used('automation.runs')))->toBe(0)
        ->and($run->graph)->not->toBeNull();

    Http::assertNothingSent();

    $steps = $this->inTenant($tenant, fn () => $run->steps()->orderBy('sequence')->get()->keyBy('node_id'));
    expect($steps->keys()->all())->toBe(['trigger', 'check', 'act', 'wait', 'ask', 'call', 'end'])
        ->and($steps['act']->output)->toMatchArray(['dry_run' => true, 'action' => 'test_flow.item.record'])
        ->and($steps['wait']->output)->toMatchArray(['skipped' => true])
        ->and($steps['ask']->output)->toMatchArray(['simulated' => true]);
});

it('evaluates conditions really, so the test shows which branch a payload takes', function () {
    $tenant = $this->flowTenant();
    $graph = $this->graphOf(
        [
            $this->node('trigger', 'trigger', ['trigger_key' => 'automation.trigger.manual', 'config' => []]),
            $this->node('check', 'condition', ['match' => 'all', 'rules' => [['field' => 'trigger.amount', 'operator' => 'gt', 'value' => 10]]]),
            $this->node('big', 'end'),
            $this->node('small', 'end'),
        ],
        [$this->edge('trigger', 'check'), $this->edge('check', 'big', 'true'), $this->edge('check', 'small', 'false')],
    );
    $workflow = $this->makeWorkflow($tenant, $graph, publish: false);

    $run = $this->inTenant($tenant, fn () => app(RunManager::class)->test($workflow, $this->ownerMembership($tenant), ['amount' => 3]));

    $nodes = $this->inTenant($tenant, fn () => $run->steps()->pluck('node_id')->all());
    expect($nodes)->toContain('small')->not->toContain('big');
});

it('refuses to test an invalid draft', function () {
    $tenant = $this->flowTenant();
    $workflow = $this->makeWorkflow($tenant, $this->graphOf([$this->node('end', 'end')], []), publish: false);

    expect(fn () => $this->inTenant($tenant, fn () => app(RunManager::class)->test($workflow, $this->ownerMembership($tenant), [])))
        ->toThrow(InvalidWorkflow::class);
});

it('still reports an action input that would be rejected, without running the action', function () {
    $tenant = $this->flowTenant();
    $graph = $this->graphOf(
        [
            $this->node('trigger', 'trigger', ['trigger_key' => 'automation.trigger.manual', 'config' => []]),
            $this->recordNode('act', ['label' => '{{ trigger.label }}']),
        ],
        [$this->edge('trigger', 'act')],
    );
    $workflow = $this->makeWorkflow($tenant, $graph, publish: false);

    $run = $this->inTenant($tenant, fn () => app(RunManager::class)->test($workflow, $this->ownerMembership($tenant), ['label' => str_repeat('x', 500)]));
    $run = $this->inTenant($tenant, fn () => Run::query()->findOrFail($run->id));

    expect($run->status)->toBe(RunStatus::Failed)->and($run->error_code)->toBe('invalid_input')->and(FlowRecorder::$calls)->toBeEmpty();
});

it('does not change the published version or start live runs', function () {
    $tenant = $this->flowTenant();
    $workflow = $this->makeWorkflow($tenant, $this->simpleGraph());
    $before = $workflow->published_version_id;

    $this->inTenant($tenant, function () use ($workflow, $tenant) {
        $manager = app(WorkflowManager::class);
        $manager->saveDraft($workflow, $this->graphOf([$this->node('trigger', 'trigger', ['trigger_key' => 'automation.trigger.manual', 'config' => []]), $this->node('end', 'end')], [$this->edge('trigger', 'end')]), $this->ownerMembership($tenant));
        app(RunManager::class)->test($workflow, $this->ownerMembership($tenant), []);
    });

    expect($workflow->refresh()->published_version_id)->toBe($before)
        ->and($this->inTenant($tenant, fn () => Run::query()->where('mode', RunMode::Live->value)->count()))->toBe(0);
});
