<?php

declare(strict_types=1);

use App\Modules\Automation\Actions\RunManager;
use App\Modules\Automation\Engine\ApprovalManager;
use App\Modules\Automation\Engine\Sweeper;
use App\Modules\Automation\Enums\ApprovalStatus;
use App\Modules\Automation\Enums\RunStatus;
use App\Modules\Automation\Models\Approval;
use App\Modules\Automation\Models\Run;
use Illuminate\Validation\ValidationException;
use Tests\Support\Fixtures\Flow\FlowRecorder;
use Tests\Support\InteractsWithAutomation;

uses(InteractsWithAutomation::class);

beforeEach(function () {
    $this->tenant = $this->flowTenant();

    [$this->starterUser, $this->starter] = $this->memberWith($this->tenant, ['automation.runs.start', 'automation.runs.view', 'automation.approvals.decide']);
    [$this->approverUser, $this->approver] = $this->memberWith($this->tenant, ['automation.approvals.decide']);
    [$this->outsiderUser, $this->outsider] = $this->memberWith($this->tenant, ['automation.runs.view']);

    $this->graphWith = fn (array $approval): array => $this->graphOf(
        [
            $this->node('trigger', 'trigger', ['trigger_key' => 'automation.trigger.manual', 'config' => []]),
            $this->node('ask', 'approval', [
                'title' => 'Approve {{ trigger.what }}',
                'approver' => ['type' => 'permission', 'permission' => 'automation.approvals.decide'],
                'prevent_self_approval' => true,
                'timeout_hours' => 1,
                'on_timeout' => 'reject',
                ...$approval,
            ]),
            $this->recordNode('yes', ['label' => 'approved']),
            $this->recordNode('no', ['label' => 'rejected']),
        ],
        [$this->edge('trigger', 'ask'), $this->edge('ask', 'yes', 'approved'), $this->edge('ask', 'no', 'rejected')],
    );

    $this->begin = function (array $approval = []): array {
        $workflow = $this->makeWorkflow($this->tenant, ($this->graphWith)($approval));
        $run = $this->inTenant($this->tenant, fn () => app(RunManager::class)->startManual($workflow, $this->starter, ['what' => 'the budget']));
        $approval = $this->inTenant($this->tenant, fn () => Approval::query()->where('run_id', $run->id)->sole());

        return [$workflow, $run, $approval];
    };
    $this->decide = fn (Approval $approval, $member, bool $approve = true) => $this->inTenant(
        $this->tenant,
        fn () => app(ApprovalManager::class)->decide($approval, $member, $approve, 'ok'),
    );
});

it('pauses the run on an approval task with a resolved title', function () {
    [, $run, $approval] = ($this->begin)();

    $run = $this->inTenant($this->tenant, fn () => Run::query()->findOrFail($run->id));
    expect($run->status)->toBe(RunStatus::AwaitingApproval)
        ->and($approval->title)->toBe('Approve the budget')
        ->and($approval->status)->toBe(ApprovalStatus::Pending)
        ->and($approval->requested_by_membership_id)->toBe($this->starter->id)
        ->and(FlowRecorder::$calls)->toBeEmpty();
});

it('never lets the person who started the run approve it when that is switched on', function () {
    [, , $approval] = ($this->begin)();

    expect(fn () => ($this->decide)($approval, $this->starter))->toThrow(ValidationException::class);

    $this->actingAsMember($this->tenant, $this->starterUser)
        ->post($this->tenantUrl($this->tenant, "/automation/approvals/{$approval->id}/decide"), ['decision' => 'approve'])
        ->assertSessionHasErrors('approval');

    expect($this->inTenant($this->tenant, fn () => $approval->refresh()->status))->toBe(ApprovalStatus::Pending)
        ->and(FlowRecorder::$calls)->toBeEmpty();
});

it('lets the starter approve when self-approval is allowed', function () {
    [$workflow, $run, $approval] = ($this->begin)(['prevent_self_approval' => false]);

    ($this->decide)($approval, $this->starter);

    expect($this->inTenant($this->tenant, fn () => Run::query()->findOrFail($run->id)->status))->toBe(RunStatus::Succeeded)
        ->and(FlowRecorder::$calls[0]['input']['label'])->toBe('approved');
});

it('lets a permitted member approve and continues the approved branch', function () {
    [, $run, $approval] = ($this->begin)();

    $this->actingAsMember($this->tenant, $this->approverUser)
        ->post($this->tenantUrl($this->tenant, "/automation/approvals/{$approval->id}/decide"), ['decision' => 'approve', 'comment' => 'fine'])
        ->assertRedirect();

    $approval = $this->inTenant($this->tenant, fn () => $approval->refresh());
    expect($approval->status)->toBe(ApprovalStatus::Approved)
        ->and($approval->decided_by_membership_id)->toBe($this->approver->id)
        ->and($approval->decision_comment)->toBe('fine')
        ->and($this->inTenant($this->tenant, fn () => Run::query()->findOrFail($run->id)->status))->toBe(RunStatus::Succeeded)
        ->and(array_map(fn ($call) => $call['input']['label'], FlowRecorder::$calls))->toBe(['approved'])
        ->and($this->auditTrail($this->tenant, 'automation.approval.approved'))->toHaveCount(1);
});

it('follows the rejected branch', function () {
    [, $run, $approval] = ($this->begin)();

    ($this->decide)($approval, $this->approver, false);

    expect(array_map(fn ($call) => $call['input']['label'], FlowRecorder::$calls))->toBe(['rejected'])
        ->and($this->inTenant($this->tenant, fn () => Run::query()->findOrFail($run->id)->status))->toBe(RunStatus::Succeeded);
});

it('refuses a member who may not decide approvals, and a second decision', function () {
    [, , $approval] = ($this->begin)();

    $this->actingAsMember($this->tenant, $this->outsiderUser)
        ->post($this->tenantUrl($this->tenant, "/automation/approvals/{$approval->id}/decide"), ['decision' => 'approve'])
        ->assertForbidden();

    ($this->decide)($approval, $this->approver);
    expect(fn () => ($this->decide)($approval, $this->approver, false))->toThrow(ValidationException::class);
});

it('restricts a named approver to that person', function () {
    $other = $this->memberWith($this->tenant, ['automation.approvals.decide']);
    [, , $approval] = ($this->begin)(['approver' => ['type' => 'membership', 'membership_id' => $this->approver->id]]);

    expect(fn () => ($this->decide)($approval, $other[1]))->toThrow(ValidationException::class);

    ($this->decide)($approval, $this->approver);
    expect($this->inTenant($this->tenant, fn () => $approval->refresh()->status))->toBe(ApprovalStatus::Approved);
});

it('expires an undecided approval and takes the timeout branch', function () {
    [, $run, $approval] = ($this->begin)();

    $this->travel(2)->hours();
    $this->inTenant($this->tenant, fn () => app(Sweeper::class)->sweep());

    expect($this->inTenant($this->tenant, fn () => $approval->refresh()->status))->toBe(ApprovalStatus::Expired)
        ->and($this->inTenant($this->tenant, fn () => Run::query()->findOrFail($run->id)->status))->toBe(RunStatus::Succeeded)
        ->and(array_map(fn ($call) => $call['input']['label'], FlowRecorder::$calls))->toBe(['rejected']);
});

it('fails the run when the approval times out with fail', function () {
    [, $run] = ($this->begin)(['on_timeout' => 'fail']);

    $this->travel(2)->hours();
    $this->inTenant($this->tenant, fn () => app(Sweeper::class)->sweep());

    $run = $this->inTenant($this->tenant, fn () => Run::query()->findOrFail($run->id));
    expect($run->status)->toBe(RunStatus::Failed)->and($run->error_code)->toBe('approval_timeout');
});

it('lists only the approvals the member may decide', function () {
    [, , $approval] = ($this->begin)();

    $this->actingAsMember($this->tenant, $this->approverUser)
        ->get($this->tenantUrl($this->tenant, '/automation/approvals'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Modules/Automation/Pages/Approvals/Index', false)
            ->has('pending', 1)->where('pending.0.id', $approval->id)->where('pending.0.title', 'Approve the budget'));

    // The starter holds the permission but may not approve their own request: it is not offered.
    $this->actingAsMember($this->tenant, $this->starterUser)
        ->get($this->tenantUrl($this->tenant, '/automation/approvals'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('pending', 0));
});

it('cancels the approval when the run is cancelled', function () {
    [, $run, $approval] = ($this->begin)();

    $this->inTenant($this->tenant, fn () => app(RunManager::class)->cancel($run, $this->starter));

    expect($this->inTenant($this->tenant, fn () => $approval->refresh()->status))->toBe(ApprovalStatus::Cancelled);
    expect(fn () => ($this->decide)($approval, $this->approver))->toThrow(ValidationException::class);
});

it('refuses a decision after the deadline and closes the approval as expired', function () {
    [, $run, $approval] = ($this->begin)();

    $this->travel(2)->hours();

    expect(fn () => ($this->decide)($approval, $this->approver))->toThrow(ValidationException::class);

    expect($this->inTenant($this->tenant, fn () => $approval->refresh()->status))->toBe(ApprovalStatus::Expired)
        ->and($this->inTenant($this->tenant, fn () => app(ApprovalManager::class)->refusal($approval->refresh(), $this->approver)))->toBe('closed');
});

it('shows approval history only to those it concerns, unless they manage runs', function () {
    $named = $this->memberWith($this->tenant, ['automation.approvals.decide']);
    $bystander = $this->memberWith($this->tenant, ['automation.approvals.decide']);
    $manager = $this->memberWith($this->tenant, ['automation.approvals.decide', 'automation.runs.manage']);
    [, , $approval] = ($this->begin)(['approver' => ['type' => 'membership', 'membership_id' => $named[1]->id]]);

    ($this->decide)($approval, $named[1]);

    $history = fn ($user) => $this->actingAsMember($this->tenant, $user)
        ->get($this->tenantUrl($this->tenant, '/automation/approvals'))
        ->assertOk();

    $history($named[0])->assertInertia(fn ($page) => $page->has('history', 1));
    $history($this->starterUser)->assertInertia(fn ($page) => $page->has('history', 1));
    $history($manager[0])->assertInertia(fn ($page) => $page->has('history', 1));
    $history($bystander[0])->assertInertia(fn ($page) => $page->has('history', 0));
});

it('hides step input, output and the trigger payload from viewers who may not manage runs', function () {
    [, $run] = ($this->begin)();
    $manager = $this->memberWith($this->tenant, ['automation.runs.view', 'automation.runs.manage']);
    $show = fn ($user) => $this->actingAsMember($this->tenant, $user)
        ->get($this->tenantUrl($this->tenant, "/automation/runs/{$run->id}"))
        ->assertOk();

    $show($this->outsiderUser)->assertInertia(fn ($page) => $page
        ->where('detailHidden', true)
        ->where('payload', [])
        ->where('steps.0.input', null)->where('steps.0.output', null)->where('steps.0.logs', [])
        ->has('approvals', 0));

    $show($manager[0])->assertInertia(fn ($page) => $page
        ->where('detailHidden', false)
        ->where('payload.what', 'the budget')
        ->has('approvals', 1));
});

it('does not store the message of an unexpected action exception on the step', function () {
    $workflow = $this->makeWorkflow($this->tenant, $this->graphOf(
        [
            $this->node('trigger', 'trigger', ['trigger_key' => 'automation.trigger.manual', 'config' => []]),
            $this->recordNode('bad', ['label' => 'x'], ['retry' => ['max_attempts' => 1, 'backoff_seconds' => 1]], 'test_flow.item.fail'),
        ],
        [$this->edge('trigger', 'bad')],
    ));

    $run = $this->inTenant($this->tenant, fn () => app(RunManager::class)->startManual($workflow, $this->starter, []));
    $step = $this->inTenant($this->tenant, fn () => Run::query()->findOrFail($run->id)->steps()->where('node_id', 'bad')->latest('sequence')->firstOrFail());

    expect($step->error_code)->toBe('action_error')
        ->and($step->error_message)->toContain('RuntimeException')
        ->and($step->error_message)->not->toContain('boom');
});
