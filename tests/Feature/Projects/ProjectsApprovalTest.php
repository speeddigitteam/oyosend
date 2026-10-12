<?php

declare(strict_types=1);

use App\Core\Identity\Enums\MembershipStatus;
use App\Core\Identity\Models\User;
use App\Core\Modules\NavigationBuilder;
use App\Modules\Projects\Actions\ApprovalManager;
use App\Modules\Projects\Actions\ProjectManager;
use App\Modules\Projects\Actions\TaskManager;
use App\Modules\Projects\Actions\TimeExpenseManager;
use App\Modules\Projects\Enums\ApprovalEntity;
use App\Modules\Projects\Enums\ApprovalStatus;
use App\Modules\Projects\Enums\EntryStatus;
use App\Modules\Projects\Http\Controllers\ProjectControllerSupport;
use App\Modules\Projects\Models\Approval;
use Illuminate\Validation\ValidationException;

it('allows only the selected current-tenant approver to decide a request once', function () {
    $tenant = $this->createTenant('projects-approval');
    $foreignTenant = $this->createTenant('projects-approval-foreign');
    $this->entitle($tenant, 'projects');
    $owner = $this->membershipOf($tenant, $this->ownerOf($tenant));
    $approverRole = $this->createRole($tenant, 'Project approvers', ['projects.approvals.decide']);
    $approver = $this->membershipOf($tenant, $this->addMember($tenant, $approverRole));
    $other = $this->membershipOf($tenant, $this->addMember($tenant));
    $foreign = $this->membershipOf($foreignTenant, $this->ownerOf($foreignTenant));

    $this->inTenant($tenant, function () use ($owner, $approver, $other, $foreign): void {
        $projects = app(ProjectManager::class);
        $project = $projects->create(['name' => 'Approval project'], $owner, $owner);
        $otherProject = $projects->create(['name' => 'Other project'], $owner, $owner);
        $task = app(TaskManager::class)->create($project, ['title' => 'Approve task'], $owner, $owner);
        $otherTask = app(TaskManager::class)->create($otherProject, ['title' => 'Wrong project'], $owner, $owner);
        $manager = app(ApprovalManager::class);

        expect(fn () => $manager->request($project, ApprovalEntity::Task, $task->id, $owner, $owner))
            ->toThrow(ValidationException::class)
            ->and(fn () => $manager->request($project, ApprovalEntity::Task, $task->id, $owner, $foreign))
            ->toThrow(ValidationException::class)
            ->and(fn () => $manager->request($project, ApprovalEntity::Task, $task->id, $owner, $other))
            ->toThrow(ValidationException::class)
            ->and(fn () => $manager->request($project, ApprovalEntity::Task, $otherTask->id, $owner, $approver))
            ->toThrow(ValidationException::class);

        $approval = $manager->request($project, ApprovalEntity::Task, $task->id, $owner, $approver, 'Ready for review');

        expect($approval->status)->toBe(ApprovalStatus::Pending)
            ->and($approval->pending_key)->toBe('task:'.$task->id)
            ->and(fn () => $manager->request($project, ApprovalEntity::Task, $task->id, $owner, $approver))
            ->toThrow(ValidationException::class)
            ->and(fn () => $manager->decide($approval, true, $other))
            ->toThrow(ValidationException::class);

        $manager->decide($approval, true, $approver, 'Approved');

        expect(fn () => $manager->decide($approval, false, $approver))
            ->toThrow(ValidationException::class);

        expect($approval->refresh()->status)->toBe(ApprovalStatus::Approved)
            ->and($approval->pending_key)->toBeNull()
            ->and($approval->decision_note)->toBe('Approved')
            ->and($approval->decided_at)->not->toBeNull();

        $nextApproval = $manager->request($project, ApprovalEntity::Task, $task->id, $owner, $approver, 'Review again');

        expect($nextApproval->pending_key)->toBe('task:'.$task->id)
            ->and(Approval::query()->where('entity_id', $task->id)->count())->toBe(2);
    });
});

it('keeps approval decisions and time or expense subject lifecycles atomic', function () {
    $tenant = $this->createTenant('projects-approval-finance');
    $this->entitle($tenant, 'projects');
    $owner = $this->membershipOf($tenant, $this->ownerOf($tenant));
    $approverRole = $this->createRole($tenant, 'Finance approvers', ['projects.approvals.decide', 'projects.time.approve', 'projects.expenses.approve']);
    $approver = $this->membershipOf($tenant, $this->addMember($tenant, $approverRole));

    $this->inTenant($tenant, function () use ($owner, $approver): void {
        $project = app(ProjectManager::class)->create(['name' => 'Financial approvals'], $owner, $owner);
        $finance = app(TimeExpenseManager::class);
        $approvals = app(ApprovalManager::class);
        $time = $finance->logTime($project, $owner, ['worked_on' => '2026-10-10', 'minutes' => 60], $owner);
        $expense = $finance->createExpense($project, $owner, ['description' => 'Taxi', 'incurred_on' => '2026-10-10', 'amount' => 500]);

        expect(fn () => $approvals->request($project, ApprovalEntity::TimeEntry, $time->id, $owner, $approver))
            ->toThrow(ValidationException::class)
            ->and(fn () => $approvals->request($project, ApprovalEntity::Expense, $expense->id, $owner, $approver))
            ->toThrow(ValidationException::class);

        $finance->submitTime($time, $owner);
        $finance->submitExpense($expense, $owner);
        $timeApproval = $approvals->request($project, ApprovalEntity::TimeEntry, $time->id, $owner, $approver);
        $expenseApproval = $approvals->request($project, ApprovalEntity::Expense, $expense->id, $owner, $approver);

        expect(fn () => $finance->decideTime($time, true, $approver))
            ->toThrow(ValidationException::class)
            ->and(fn () => $finance->decideExpense($expense, true, $approver))
            ->toThrow(ValidationException::class)
            ->and($time->refresh()->status)->toBe(EntryStatus::Submitted)
            ->and($expense->refresh()->status)->toBe(EntryStatus::Submitted)
            ->and($timeApproval->refresh()->status)->toBe(ApprovalStatus::Pending)
            ->and($expenseApproval->refresh()->status)->toBe(ApprovalStatus::Pending);

        $approvals->decide($timeApproval, true, $approver);
        $approvals->decide($expenseApproval, false, $approver);

        expect($timeApproval->refresh()->status)->toBe(ApprovalStatus::Approved)
            ->and($time->refresh()->status)->toBe(EntryStatus::Approved)
            ->and($expenseApproval->refresh()->status)->toBe(ApprovalStatus::Rejected)
            ->and($expense->refresh()->status)->toBe(EntryStatus::Rejected);
    });
});

it('lets a requester recover a pending approval after its approver becomes unavailable', function () {
    $tenant = $this->createTenant('projects-approval-recovery');
    $this->entitle($tenant, 'projects');
    $owner = $this->membershipOf($tenant, $this->ownerOf($tenant));
    $approverRole = $this->createRole($tenant, 'Recovery approvers', ['projects.approvals.decide']);
    $approver = $this->membershipOf($tenant, $this->addMember($tenant, $approverRole));
    $replacement = $this->membershipOf($tenant, $this->addMember($tenant, $approverRole));
    $recoverer = $this->membershipOf($tenant, $this->addMember($tenant, $approverRole));

    $this->inTenant($tenant, function () use ($owner, $approver, $replacement, $recoverer): void {
        $project = app(ProjectManager::class)->create(['name' => 'Approval recovery'], $owner, $owner);
        $task = app(TaskManager::class)->create($project, ['title' => 'Recover review'], $owner, $owner);
        $manager = app(ApprovalManager::class);
        $approval = $manager->request($project, ApprovalEntity::Task, $task->id, $owner, $approver);

        expect(fn () => $manager->cancel($approval, $recoverer))->toThrow(ValidationException::class);

        $approver->update(['status' => MembershipStatus::Suspended]);
        $manager->cancel($approval, $recoverer);

        expect($approval->refresh()->status)->toBe(ApprovalStatus::Cancelled)
            ->and($approval->pending_key)->toBeNull()
            ->and($approval->decided_at)->not->toBeNull()
            ->and(fn () => $manager->cancel($approval, $owner))->toThrow(ValidationException::class);

        $replacementApproval = $manager->request($project, ApprovalEntity::Task, $task->id, $owner, $replacement);

        expect($replacementApproval->status)->toBe(ApprovalStatus::Pending)
            ->and($replacementApproval->pending_key)->toBe('task:'.$task->id);
    });
});

it('shows a stranded pending approval to an eligible recovery decider', function () {
    $tenant = $this->createTenant('projects-approval-recovery-queue');
    $this->entitle($tenant, 'projects');
    $owner = $this->membershipOf($tenant, $this->ownerOf($tenant));
    $approverRole = $this->createRole($tenant, 'Queue recovery approvers', ['projects.approvals.decide']);
    $approver = $this->membershipOf($tenant, $this->addMember($tenant, $approverRole));
    $recovererUser = $this->addMember($tenant, $approverRole);

    $approval = $this->inTenant($tenant, function () use ($owner, $approver): Approval {
        $project = app(ProjectManager::class)->create(['name' => 'Stranded approval'], $owner, $owner);
        $task = app(TaskManager::class)->create($project, ['title' => 'Needs recovery'], $owner, $owner);
        $approval = app(ApprovalManager::class)->request($project, ApprovalEntity::Task, $task->id, $owner, $approver);
        $approver->update(['status' => MembershipStatus::Suspended]);

        return $approval;
    });

    $this->actingAsMember($tenant, $recovererUser)
        ->get($this->tenantUrl($tenant, '/project-approvals'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('approvals.total', 1)
            ->where('approvals.data.0.id', $approval->id)
            ->where('approvals.data.0.cancelable', true));
});

it('authorizes approval cancellation over HTTP and projects the recovery action', function () {
    $tenant = $this->createTenant('projects-approval-cancel-http');
    $this->entitle($tenant, 'projects');
    $owner = $this->membershipOf($tenant, $this->ownerOf($tenant));
    $approverRole = $this->createRole($tenant, 'HTTP approvers', ['projects.approvals.decide']);
    $approver = $this->membershipOf($tenant, $this->addMember($tenant, $approverRole));
    $requesterRole = $this->createRole($tenant, 'HTTP requesters', ['projects.approvals.request']);
    $requesterUser = $this->addMember($tenant, $requesterRole);
    $requester = $this->membershipOf($tenant, $requesterUser);
    $otherRequester = $this->addMember($tenant, $requesterRole);
    $viewer = $this->addMember($tenant, $this->createRole($tenant, 'Approval viewers', ['projects.approvals.view']));

    [$approval, $otherApproval] = $this->inTenant($tenant, function () use ($owner, $approver, $requester): array {
        $project = app(ProjectManager::class)->create(['name' => 'HTTP recovery'], $owner, $owner);
        $task = app(TaskManager::class)->create($project, ['title' => 'HTTP review'], $owner, $owner);
        $otherTask = app(TaskManager::class)->create($project, ['title' => 'Private review'], $owner, $owner);
        $manager = app(ApprovalManager::class);

        return [
            $manager->request($project, ApprovalEntity::Task, $task->id, $requester, $approver),
            $manager->request($project, ApprovalEntity::Task, $otherTask->id, $owner, $approver),
        ];
    });

    $this->actingAsMember($tenant, $requesterUser)
        ->get($this->tenantUrl($tenant, '/project-approvals'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Modules/Projects/Pages/Approvals/Index')
            ->where('approvals.total', 1)
            ->where('approvals.data.0.id', $approval->id)
            ->where('approvals.data.0.cancelable', true)
            ->missing('approvals.data.1'));

    expect($otherApproval->status)->toBe(ApprovalStatus::Pending);

    $this->actingAsMember($tenant, $otherRequester)
        ->patch($this->tenantUrl($tenant, "/project-approvals/{$approval->id}/cancel"))
        ->assertRedirect()
        ->assertSessionHasErrors('status');

    $this->actingAsMember($tenant, $viewer)
        ->patch($this->tenantUrl($tenant, "/project-approvals/{$approval->id}/cancel"))
        ->assertForbidden();

    expect($this->inTenant($tenant, fn (): ApprovalStatus => $approval->refresh()->status))->toBe(ApprovalStatus::Pending);

    $this->actingAsMember($tenant, $requesterUser)
        ->patch($this->tenantUrl($tenant, "/project-approvals/{$approval->id}/cancel"))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($this->inTenant($tenant, fn (): ApprovalStatus => $approval->refresh()->status))->toBe(ApprovalStatus::Cancelled);
});

it('lists only active current-tenant members who can decide approvals', function () {
    $tenant = $this->createTenant('projects-approval-options');
    $foreignTenant = $this->createTenant('projects-approval-options-foreign');
    $this->entitle($tenant, 'projects');
    $this->entitle($foreignTenant, 'projects');
    $owner = $this->membershipOf($tenant, $this->ownerOf($tenant));
    $role = $this->createRole($tenant, 'Option approvers', ['projects.approvals.decide']);
    $eligible = $this->membershipOf($tenant, $this->addMember($tenant, $role));
    $suspended = $this->membershipOf($tenant, $this->addMember($tenant, $role));
    $ordinary = $this->membershipOf($tenant, $this->addMember($tenant));
    $foreignRole = $this->createRole($foreignTenant, 'Foreign approvers', ['projects.approvals.decide']);
    $foreign = $this->membershipOf($foreignTenant, $this->addMember($foreignTenant, $foreignRole));
    $suspended->update(['status' => MembershipStatus::Suspended]);

    $options = $this->inTenant($tenant, fn (): array => app(ProjectControllerSupport::class)->approvalMemberOptions());
    $ids = collect($options)->pluck('id')->all();

    expect($ids)->toContain($owner->id, $eligible->id)
        ->not->toContain($suspended->id, $ordinary->id, $foreign->id);
});

it('shows the approval sidebar entry to request-only and decide-only members', function () {
    $tenant = $this->createTenant('projects-approval-navigation');
    $this->entitle($tenant, 'projects');
    $requester = $this->addMember($tenant, $this->createRole($tenant, 'Navigation requesters', ['projects.approvals.request']));
    $decider = $this->addMember($tenant, $this->createRole($tenant, 'Navigation deciders', ['projects.approvals.decide']));

    $keysFor = function (User $user) use ($tenant): array {
        return collect($this->inTenant($tenant, fn (): array => app(NavigationBuilder::class)->for($user)))
            ->flatMap(fn (array $section): array => array_column($section['items'], 'key'))
            ->values()
            ->all();
    };

    expect($keysFor($requester))->toContain('projectApprovals')
        ->and($keysFor($decider))->toContain('projectApprovals');
});
