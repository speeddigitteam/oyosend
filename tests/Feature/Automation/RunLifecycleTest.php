<?php

declare(strict_types=1);

use App\Core\Entitlements\Contracts\EntitlementChecker;
use App\Modules\Automation\Actions\RunManager;
use App\Modules\Automation\Engine\RunExecutor;
use App\Modules\Automation\Engine\Sweeper;
use App\Modules\Automation\Enums\RunSource;
use App\Modules\Automation\Enums\RunStatus;
use App\Modules\Automation\Enums\StepStatus;
use App\Modules\Automation\Models\Run;
use App\Modules\Automation\Models\RunStep;
use App\Modules\Automation\Models\Workflow;
use Illuminate\Support\Facades\Queue;
use Tests\Support\Fixtures\Flow\FlowRecorder;
use Tests\Support\Fixtures\Flow\ItemCreated;
use Tests\Support\InteractsWithAutomation;

uses(InteractsWithAutomation::class);

beforeEach(function () {
    $this->tenant = $this->flowTenant();
    $this->manual = fn (array $graphNodes, array $edges): array => $this->graphOf(
        [$this->node('trigger', 'trigger', ['trigger_key' => 'automation.trigger.manual', 'config' => []]), ...$graphNodes],
        $edges,
    );
    $this->startManual = fn (Workflow $workflow, array $payload = []): Run => $this->inTenant(
        $this->tenant,
        fn (): Run => app(RunManager::class)->startManual($workflow, $this->ownerMembership($this->tenant), $payload),
    );
    $this->sweep = fn (): array => $this->inTenant($this->tenant, fn (): array => app(Sweeper::class)->sweep());
});

it('starts exactly one run for an event delivered twice', function () {
    $workflow = $this->makeWorkflow($this->tenant, $this->simpleGraph());

    $this->inTenant($this->tenant, function () {
        $event = new ItemCreated('same', 10);
        event($event);
        event($event);
    });

    expect($this->runsOf($this->tenant, $workflow))->toHaveCount(1)->and(FlowRecorder::$calls)->toHaveCount(1);

    // A different event, even with an identical payload, is a different run.
    $this->raise($this->tenant, 'same', 10);
    expect($this->runsOf($this->tenant, $workflow))->toHaveCount(2);
});

it('survives a restart: a day-long delay resumes from the database through the sweeper', function () {
    $graph = ($this->manual)(
        [
            $this->recordNode('first', ['label' => 'before']),
            $this->node('wait', 'delay', ['amount' => 3, 'unit' => 'days']),
            $this->recordNode('second', ['label' => 'after']),
        ],
        [$this->edge('trigger', 'first'), $this->edge('first', 'wait'), $this->edge('wait', 'second')],
    );
    $workflow = $this->makeWorkflow($this->tenant, $graph);
    $run = ($this->startManual)($workflow);

    expect($run->refresh()->status)->toBe(RunStatus::Waiting)
        ->and($run->resume_at->isAfter(now()->addDays(2)))->toBeTrue()
        ->and(FlowRecorder::$calls)->toHaveCount(1);

    // "Deploy": every in-memory service is thrown away; nothing but the database remains.
    app()->forgetScopedInstances();

    // Not yet due: the sweeper has nothing to do.
    $this->travel(1)->days();
    expect(($this->sweep)()['dispatched'])->toBe(0)->and(FlowRecorder::$calls)->toHaveCount(1);

    $this->travel(3)->days();
    expect(($this->sweep)()['dispatched'])->toBe(1);

    $run = $this->inTenant($this->tenant, fn () => Run::query()->findOrFail($run->id));
    expect($run->status)->toBe(RunStatus::Succeeded)
        ->and(array_map(fn ($call) => $call['input']['label'], FlowRecorder::$calls))->toBe(['before', 'after']);
});

it('recovers a run abandoned by a crashed worker without repeating the finished work', function () {
    $workflow = $this->makeWorkflow($this->tenant, $this->simpleGraph());
    $realQueue = app('queue');
    Queue::fake(); // the job that would have run is lost

    $this->raise($this->tenant);
    $run = $this->onlyRun($this->tenant, $workflow);
    expect($run->status)->toBe(RunStatus::Queued);
    Queue::swap($realQueue); // restore a real (sync) queue for the recovery

    // A worker took the lease, started the action step and died.
    $this->inTenant($this->tenant, function () use ($run) {
        $executor = app(RunExecutor::class);
        $executor->advance($run->id); // trigger step done
        $step = RunStep::query()->where('run_id', $run->id)->where('status', StepStatus::Pending)->orderBy('sequence')->first();
        $step->forceFill(['status' => StepStatus::Running, 'attempt' => 1])->save();
        Run::query()->whereKey($run->id)->update(['status' => RunStatus::Running, 'locked_until' => now()->subMinute()]);
    });

    ($this->sweep)();
    $run = $this->drain($this->tenant, $run);

    expect($run->status)->toBe(RunStatus::Succeeded)->and(FlowRecorder::$calls)->toHaveCount(1);
});

it('fires cron schedules once per slot and skips missed ones', function () {
    $graph = $this->graphOf(
        [$this->node('trigger', 'trigger', ['trigger_key' => 'automation.trigger.schedule', 'config' => ['cron' => '*/10 * * * *']]), $this->recordNode('act')],
        [$this->edge('trigger', 'act')],
    );
    $workflow = $this->makeWorkflow($this->tenant, $graph);
    expect($workflow->next_run_at)->not->toBeNull();

    expect(($this->sweep)()['scheduled'])->toBe(0);

    $this->travel(11)->minutes();
    expect(($this->sweep)()['scheduled'])->toBe(1)
        ->and(($this->sweep)()['scheduled'])->toBe(0); // same slot: nothing more

    // The workspace was "down" for three hours: one catch-up run, not eighteen.
    $this->travel(3)->hours();
    expect(($this->sweep)()['scheduled'])->toBe(1);

    $runs = $this->runsOf($this->tenant, $workflow);
    expect($runs)->toHaveCount(2)->and($runs[0]->source)->toBe(RunSource::Schedule)
        ->and($runs[0]->status)->toBe(RunStatus::Succeeded);
});

it('waits for the next cron time in the workspace timezone with a schedule node', function () {
    $this->travelTo(now()->utc()->setTime(20, 0)); // 02:00 the next day in Dhaka (UTC+6)
    $graph = ($this->manual)(
        [$this->node('until', 'schedule', ['mode' => 'cron', 'cron' => '0 9 * * *']), $this->recordNode('act')],
        [$this->edge('trigger', 'until'), $this->edge('until', 'act')],
    );
    $workflow = $this->makeWorkflow($this->tenant, $graph);
    $run = ($this->startManual)($workflow)->refresh();

    // 09:00 Asia/Dhaka is 03:00 UTC.
    expect($run->status)->toBe(RunStatus::Waiting)
        ->and($run->resume_at->utc()->format('H:i'))->toBe('03:00');
});

it('runs each loop iteration once and stops at the hard limit', function () {
    $graph = ($this->manual)(
        [
            $this->node('each', 'loop', ['items' => 'trigger.items', 'max_iterations' => 100]),
            $this->recordNode('body', ['label' => '{{ loop.item }}']),
            $this->node('end', 'end'),
        ],
        [$this->edge('trigger', 'each'), $this->edge('each', 'body', 'body'), $this->edge('body', 'each', null, true), $this->edge('each', 'end', 'done')],
    );
    $workflow = $this->makeWorkflow($this->tenant, $graph);

    $run = ($this->startManual)($workflow, ['items' => ['a', 'b', 'c']])->refresh();
    expect($run->status)->toBe(RunStatus::Succeeded)
        ->and(array_map(fn ($call) => $call['input']['label'], FlowRecorder::$calls))->toBe(['a', 'b', 'c']);

    FlowRecorder::reset();
    config(['automation.limits.max_loop_iterations' => 3]);
    $run = ($this->startManual)($workflow, ['items' => ['a', 'b', 'c', 'd']])->refresh();

    expect($run->status)->toBe(RunStatus::Failed)->and($run->error_code)->toBe('loop_limit')->and(FlowRecorder::$calls)->toBeEmpty();
});

it('stops a run at the maximum number of steps', function () {
    config(['automation.limits.max_steps_per_run' => 8]);
    $graph = ($this->manual)(
        [$this->node('each', 'loop', ['count' => 50]), $this->recordNode('body'), $this->node('end', 'end')],
        [$this->edge('trigger', 'each'), $this->edge('each', 'body', 'body'), $this->edge('body', 'each', null, true), $this->edge('each', 'end', 'done')],
    );
    $workflow = $this->makeWorkflow($this->tenant, $graph);

    $run = ($this->startManual)($workflow)->refresh();

    expect($run->status)->toBe(RunStatus::Failed)->and($run->error_code)->toBe('step_limit')
        ->and($run->steps_executed)->toBe(8);
});

it('runs parallel branches and joins them once', function () {
    $graph = ($this->manual)(
        [
            $this->node('fork', 'parallel', ['join_node' => 'join']),
            $this->recordNode('a', ['label' => 'a']),
            $this->recordNode('b', ['label' => 'b']),
            $this->recordNode('join', ['label' => 'joined']),
            $this->node('end', 'end'),
        ],
        [
            $this->edge('trigger', 'fork'),
            $this->edge('fork', 'a'), $this->edge('fork', 'b'),
            $this->edge('a', 'join'), $this->edge('b', 'join'),
            $this->edge('join', 'end'),
        ],
    );
    $workflow = $this->makeWorkflow($this->tenant, $graph);

    $run = ($this->startManual)($workflow)->refresh();
    $labels = array_map(fn ($call) => $call['input']['label'], FlowRecorder::$calls);

    expect($run->status)->toBe(RunStatus::Succeeded)
        ->and($labels)->toHaveCount(3)->and(end($labels))->toBe('joined')
        ->and(array_slice($labels, 0, 2))->toEqualCanonicalizing(['a', 'b']);
});

it('finishes a parallel step whose branches never meet', function () {
    $graph = ($this->manual)(
        [$this->node('fork', 'parallel'), $this->recordNode('a', ['label' => 'a']), $this->recordNode('b', ['label' => 'b'])],
        [$this->edge('trigger', 'fork'), $this->edge('fork', 'a'), $this->edge('fork', 'b')],
    );
    $workflow = $this->makeWorkflow($this->tenant, $graph);

    $run = ($this->startManual)($workflow)->refresh();

    expect($run->status)->toBe(RunStatus::Succeeded)->and(FlowRecorder::$calls)->toHaveCount(2);
});

it('limits chains of workflows triggering workflows by depth', function () {
    config(['automation.limits.max_depth' => 3]);
    $emit = fn (string $kind) => $this->graphOf(
        [$this->eventTrigger(['kind' => $kind]), $this->recordNode('emit', ['item_id' => 'chain'], [], 'test_flow.item.emit')],
        [$this->edge('trigger', 'emit')],
    );
    $first = $this->makeWorkflow($this->tenant, $emit('standard'), name: 'First');
    $loop = $this->makeWorkflow($this->tenant, $emit('emitted'), name: 'Loop');

    $this->raise($this->tenant, 'start', 1, 'standard');

    $runs = $this->runsOf($this->tenant, $loop);
    expect($this->runsOf($this->tenant, $first))->toHaveCount(1)
        ->and(collect($runs)->pluck('depth')->all())->toBe([1, 2, 3, 4])
        ->and(collect($runs)->last()->status)->toBe(RunStatus::Failed)
        ->and(collect($runs)->last()->error_code)->toBe('depth_limit')
        ->and(FlowRecorder::$calls)->toHaveCount(4); // first + three that were allowed to run
});

it('drops runs beyond the per-workflow rate limit', function () {
    config(['automation.limits.runs_per_minute_workflow' => 2]);
    $workflow = $this->makeWorkflow($this->tenant, $this->simpleGraph());

    foreach (range(1, 5) as $i) {
        $this->raise($this->tenant, "i{$i}", $i);
    }

    expect($this->runsOf($this->tenant, $workflow))->toHaveCount(2);
});

it('leaves a run queued while the workspace is at its concurrency limit', function () {
    config(['automation.limits.concurrent_runs' => 1]);
    $workflow = $this->makeWorkflow($this->tenant, $this->simpleGraph());
    $other = $this->makeWorkflow($this->tenant, $this->simpleGraph(), name: 'Other');

    $realQueue = app('queue');
    Queue::fake();
    $this->raise($this->tenant);
    Queue::swap($realQueue);

    $queued = collect($this->runsOf($this->tenant, $workflow))->first();
    $busy = collect($this->runsOf($this->tenant, $other))->first();
    $this->inTenant($this->tenant, fn () => Run::query()->whereKey($busy->id)->update(['status' => RunStatus::Running, 'locked_until' => now()->addMinute()]));

    $advanced = $this->inTenant($this->tenant, fn () => app(RunExecutor::class)->advance($queued->id));
    expect($advanced)->toBeFalse()
        ->and($this->inTenant($this->tenant, fn () => Run::query()->findOrFail($queued->id)->status))->toBe(RunStatus::Queued);

    $this->inTenant($this->tenant, fn () => Run::query()->whereKey($busy->id)->update(['status' => RunStatus::Succeeded, 'locked_until' => null]));
    expect($this->drain($this->tenant, $queued)->status)->toBe(RunStatus::Succeeded);
});

it('cancels a waiting run and never resumes it', function () {
    $graph = ($this->manual)(
        [$this->node('wait', 'delay', ['amount' => 1, 'unit' => 'days']), $this->recordNode('act')],
        [$this->edge('trigger', 'wait'), $this->edge('wait', 'act')],
    );
    $workflow = $this->makeWorkflow($this->tenant, $graph);
    $run = ($this->startManual)($workflow)->refresh();
    expect($run->status)->toBe(RunStatus::Waiting);

    $this->inTenant($this->tenant, fn () => app(RunManager::class)->cancel($run, $this->ownerMembership($this->tenant)));
    $this->travel(2)->days();
    ($this->sweep)();

    $run = $this->inTenant($this->tenant, fn () => Run::query()->findOrFail($run->id));
    expect($run->status)->toBe(RunStatus::Cancelled)->and(FlowRecorder::$calls)->toBeEmpty();
    expect($this->stepStatuses($this->tenant, $run))->toContain('cancelled');
});

it('retries a failed run from the failed step, once the cause is fixed', function () {
    FlowRecorder::$failures['flaky'] = 5;
    $graph = $this->graphOf(
        [
            $this->eventTrigger(),
            $this->recordNode('one', ['label' => 'one']),
            $this->recordNode('two', ['label' => 'two'], ['retry' => ['max_attempts' => 1, 'backoff_seconds' => 1]], 'test_flow.item.flaky'),
            $this->recordNode('three', ['label' => 'three']),
        ],
        [$this->edge('trigger', 'one'), $this->edge('one', 'two'), $this->edge('two', 'three')],
    );
    $workflow = $this->makeWorkflow($this->tenant, $graph);

    $this->raise($this->tenant);
    $run = $this->onlyRun($this->tenant, $workflow);
    expect($run->status)->toBe(RunStatus::Failed)->and(FlowRecorder::$calls)->toHaveCount(1);

    FlowRecorder::$failures['flaky'] = 0;
    $this->inTenant($this->tenant, fn () => app(RunManager::class)->retry($run, $this->ownerMembership($this->tenant)));

    $run = $this->inTenant($this->tenant, fn () => Run::query()->findOrFail($run->id));
    expect($run->status)->toBe(RunStatus::Succeeded)->and($run->retry_count)->toBe(1)
        ->and(array_map(fn ($call) => $call['input']['label'], FlowRecorder::$calls))->toBe(['one', 'two', 'three']);
});

it('ends a run as failed at a failing end node', function () {
    $graph = ($this->manual)(
        [$this->node('stop', 'end', ['status' => 'failed', 'message' => 'Nope'])],
        [$this->edge('trigger', 'stop')],
    );
    $workflow = $this->makeWorkflow($this->tenant, $graph);

    $run = ($this->startManual)($workflow)->refresh();

    expect($run->status)->toBe(RunStatus::Failed)->and($run->error_code)->toBe('end_failed');
});

it('takes the first matching case of a branch, or default', function () {
    $graph = ($this->manual)(
        [
            $this->node('pick', 'branch', ['cases' => [
                ['id' => 'vip', 'match' => 'all', 'rules' => [['field' => 'trigger.tier', 'operator' => 'equals', 'value' => 'vip']]],
                ['id' => 'big', 'match' => 'all', 'rules' => [['field' => 'trigger.amount', 'operator' => 'gt', 'value' => 100]]],
            ]]),
            $this->recordNode('vip', ['label' => 'vip']),
            $this->recordNode('big', ['label' => 'big']),
            $this->recordNode('other', ['label' => 'other']),
        ],
        [
            $this->edge('trigger', 'pick'),
            $this->edge('pick', 'vip', 'vip'), $this->edge('pick', 'big', 'big'), $this->edge('pick', 'other', 'default'),
        ],
    );
    $workflow = $this->makeWorkflow($this->tenant, $graph);

    ($this->startManual)($workflow, ['tier' => 'vip', 'amount' => 500]);
    ($this->startManual)($workflow, ['tier' => 'basic', 'amount' => 500]);
    ($this->startManual)($workflow, ['tier' => 'basic', 'amount' => 5]);

    expect(array_map(fn ($call) => $call['input']['label'], FlowRecorder::$calls))->toBe(['vip', 'big', 'other']);
});

it('is resumed by the entitlement-gated app scheduler, and only for entitled workspaces', function () {
    $graph = ($this->manual)(
        [$this->node('wait', 'delay', ['amount' => 1, 'unit' => 'days']), $this->recordNode('act')],
        [$this->edge('trigger', 'wait'), $this->edge('wait', 'act')],
    );
    $workflow = $this->makeWorkflow($this->tenant, $graph);
    $run = ($this->startManual)($workflow)->refresh();
    expect($run->status)->toBe(RunStatus::Waiting);

    $this->travel(2)->days();

    // Without the entitlement the scheduler does not dispatch the sweep at all.
    $this->revokeModules($this->tenant, 'automation');
    $this->artisan('apps:run-scheduled', ['--tenant' => $this->tenant->slug])->assertSuccessful();
    expect(FlowRecorder::$calls)->toBeEmpty();

    $this->app->forgetScopedInstances();
    $checker = app(EntitlementChecker::class);
    $checker->grant($this->tenant, 'automation');

    $this->artisan('apps:run-scheduled', ['--tenant' => $this->tenant->slug])->assertSuccessful();

    expect($this->inTenant($this->tenant, fn () => Run::query()->findOrFail($run->id)->status))->toBe(RunStatus::Succeeded)
        ->and(FlowRecorder::$calls)->toHaveCount(1);
});
