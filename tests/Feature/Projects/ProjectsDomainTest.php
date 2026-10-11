<?php

declare(strict_types=1);

use App\Core\Identity\Enums\MembershipStatus;
use App\Modules\Projects\Actions\PlanningManager;
use App\Modules\Projects\Actions\ProjectManager;
use App\Modules\Projects\Actions\TaskManager;
use App\Modules\Projects\Enums\MilestoneStatus;
use App\Modules\Projects\Enums\ProjectStatus;
use App\Modules\Projects\Enums\SprintStatus;
use App\Modules\Projects\Enums\TaskStatus;
use App\Modules\Projects\Events\TaskReminderDue;
use App\Modules\Projects\Models\ActivityHistory;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Models\ProjectMember;
use App\Modules\Projects\Models\Task;
use App\Modules\Projects\Models\TaskAssignee;
use App\Modules\Projects\Models\TaskWatcher;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;

it('enforces project and task lifecycle invariants and updates progress', function () {
    $tenant = $this->createTenant('projects-lifecycle');
    $owner = $this->membershipOf($tenant, $this->ownerOf($tenant));

    $this->inTenant($tenant, function () use ($owner): void {
        $projects = app(ProjectManager::class);
        $tasks = app(TaskManager::class);
        $project = $projects->create(['name' => 'Website launch', 'status' => 'completed', 'progress' => 100], $owner, $owner);

        expect($project->status)->toBe(ProjectStatus::Planned)
            ->and($project->progress)->toBe(0)
            ->and(ProjectMember::query()->where('project_id', $project->id)->where('membership_id', $owner->id)->exists())->toBeTrue();

        $projects->changeStatus($project, ProjectStatus::Active, $owner);
        $task = $tasks->create($project, ['title' => 'Build release'], $owner, $owner);

        expect(fn () => $tasks->create($project, ['title' => " \t "], $owner, $owner))
            ->toThrow(ValidationException::class)
            ->and(fn () => $tasks->update($task, ['title' => '   '], $owner))
            ->toThrow(ValidationException::class)
            ->and($task->refresh()->title)->toBe('Build release')
            ->and(fn () => $projects->changeStatus($project->refresh(), ProjectStatus::Completed, $owner))
            ->toThrow(ValidationException::class);

        $tasks->changeStatus($task, TaskStatus::Completed, $owner);
        expect($project->refresh()->progress)->toBe(100);

        $projects->changeStatus($project, ProjectStatus::Completed, $owner);
        expect(fn () => $projects->changeStatus($project, ProjectStatus::OnHold, $owner))
            ->toThrow(ValidationException::class);

        expect($project->refresh()->status)->toBe(ProjectStatus::Completed)
            ->and($project->completed_at)->not->toBeNull()
            ->and(fn () => $projects->changeStatus($project, ProjectStatus::Active, $owner))
            ->toThrow(ValidationException::class)
            ->and(fn () => $tasks->create($project, ['title' => 'Late open task'], $owner, $owner))
            ->toThrow(ValidationException::class);

        expect($project->refresh()->progress)->toBe(100);

        expect(ActivityHistory::query()->where('project_id', $project->id)->pluck('event'))
            ->toContain('projects.project.created', 'projects.project.status_changed', 'projects.task.created', 'projects.task.status_changed');
    });
});

it('keeps project activity history immutable', function () {
    $tenant = $this->createTenant('projects-activity-history');
    $owner = $this->membershipOf($tenant, $this->ownerOf($tenant));

    $this->inTenant($tenant, function () use ($owner): void {
        $project = app(ProjectManager::class)->create(['name' => 'Immutable history'], $owner, $owner);
        $history = ActivityHistory::query()->where('project_id', $project->id)->sole();
        $summary = $history->summary;

        expect(fn () => $history->update(['summary' => 'Rewritten history']))
            ->toThrow(LogicException::class, 'immutable')
            ->and(fn () => $history->delete())
            ->toThrow(LogicException::class, 'immutable')
            ->and(ActivityHistory::query()->findOrFail($history->id)->summary)->toBe($summary);
    });
});

it('blocks unfinished dependencies, dependency cycles, cross-project links, and subtask cycles', function () {
    $tenant = $this->createTenant('projects-dependencies');
    $owner = $this->membershipOf($tenant, $this->ownerOf($tenant));

    $this->inTenant($tenant, function () use ($owner): void {
        $projects = app(ProjectManager::class);
        $tasks = app(TaskManager::class);
        $project = $projects->create(['name' => 'Dependency graph'], $owner, $owner);
        $otherProject = $projects->create(['name' => 'Other graph'], $owner, $owner);
        $a = $tasks->create($project, ['title' => 'A'], $owner, $owner);
        $b = $tasks->create($project, ['title' => 'B'], $owner, $owner);
        $c = $tasks->create($project, ['title' => 'C'], $owner, $owner);
        $foreign = $tasks->create($otherProject, ['title' => 'Foreign'], $owner, $owner);

        $tasks->addDependency($b, $a, actor: $owner);
        $tasks->addDependency($c, $b, actor: $owner);

        expect(fn () => $tasks->changeStatus($c, TaskStatus::Completed, $owner))->toThrow(ValidationException::class)
            ->and(fn () => $tasks->addDependency($a, $c, actor: $owner))->toThrow(ValidationException::class)
            ->and(fn () => $tasks->addDependency($a, $a, actor: $owner))->toThrow(ValidationException::class)
            ->and(fn () => $tasks->addDependency($a, $foreign, actor: $owner))->toThrow(ValidationException::class);

        $tasks->changeStatus($a, TaskStatus::Completed, $owner);
        $tasks->changeStatus($b, TaskStatus::Completed, $owner);
        $tasks->changeStatus($c, TaskStatus::Completed, $owner);
        expect(fn () => $tasks->changeStatus($c, TaskStatus::InProgress, $owner))
            ->toThrow(ValidationException::class);

        expect($c->refresh()->status)->toBe(TaskStatus::Completed);

        $parent = $tasks->create($project, ['title' => 'Parent'], $owner, $owner);
        $child = $tasks->create($project, ['title' => 'Child', 'parent_id' => $parent->id], $owner, $owner);
        $grandchild = $tasks->create($project, ['title' => 'Grandchild', 'parent_id' => $child->id], $owner, $owner);

        expect(fn () => $tasks->update($parent, ['parent_id' => $grandchild->id], $owner))
            ->toThrow(ValidationException::class);

        expect(fn () => $tasks->changeStatus($parent, TaskStatus::Completed, $owner))
            ->toThrow(ValidationException::class);

        $tasks->changeStatus($grandchild, TaskStatus::Completed, $owner);
        $tasks->changeStatus($child, TaskStatus::Completed, $owner);
        $tasks->changeStatus($parent, TaskStatus::Completed, $owner);

        expect(fn () => $tasks->create($project, ['title' => 'Late child', 'parent_id' => $parent->id], $owner, $owner))
            ->toThrow(ValidationException::class);
    });
});

it('rechecks milestone and sprint transitions from locked database state', function () {
    $tenant = $this->createTenant('projects-planning-lifecycle');
    $owner = $this->membershipOf($tenant, $this->ownerOf($tenant));

    $this->inTenant($tenant, function () use ($owner): void {
        $projects = app(ProjectManager::class);
        $project = $projects->create(['name' => 'Planning lifecycle'], $owner, $owner);
        $otherProject = $projects->create(['name' => 'Other planning project'], $owner, $owner);
        $planning = app(PlanningManager::class);
        $milestone = $planning->createMilestone($project, [
            'project_id' => $otherProject->id,
            'name' => 'Release',
            'due_on' => '2026-10-24',
            'status' => MilestoneStatus::Completed,
            'completed_at' => now(),
        ], $owner);
        $sprint = $planning->createSprint($project, [
            'project_id' => $otherProject->id,
            'name' => 'Sprint 1',
            'starts_on' => '2026-10-10',
            'ends_on' => '2026-10-24',
            'status' => SprintStatus::Completed,
            'completed_at' => now(),
        ], $owner);

        expect($milestone->project_id)->toBe($project->id)
            ->and($milestone->status)->toBe(MilestoneStatus::Open)
            ->and($milestone->completed_at)->toBeNull()
            ->and($sprint->project_id)->toBe($project->id)
            ->and($sprint->status)->toBe(SprintStatus::Planned)
            ->and($sprint->completed_at)->toBeNull();

        $planning->changeMilestoneStatus($milestone, MilestoneStatus::Completed, $owner);
        $planning->changeSprintStatus($sprint, SprintStatus::Active, $owner);
        $planning->changeSprintStatus($sprint, SprintStatus::Completed, $owner);
        $tasks = app(TaskManager::class);

        expect(fn () => $planning->changeMilestoneStatus($milestone, MilestoneStatus::Cancelled, $owner))
            ->toThrow(ValidationException::class)
            ->and(fn () => $planning->changeSprintStatus($sprint, SprintStatus::Cancelled, $owner))
            ->toThrow(ValidationException::class)
            ->and(fn () => $tasks->create($project, ['title' => 'Late milestone task', 'milestone_id' => $milestone->id], $owner, $owner))
            ->toThrow(ValidationException::class)
            ->and(fn () => $tasks->create($project, ['title' => 'Late sprint task', 'sprint_id' => $sprint->id], $owner, $owner))
            ->toThrow(ValidationException::class)
            ->and($milestone->refresh()->status)->toBe(MilestoneStatus::Completed)
            ->and($sprint->refresh()->status)->toBe(SprintStatus::Completed);

        $projects->changeStatus($project, ProjectStatus::Active, $owner);
        $projects->changeStatus($project, ProjectStatus::Completed, $owner);
        $projects->changeStatus($otherProject, ProjectStatus::Cancelled, $owner);

        expect(fn () => $planning->createMilestone($project, ['name' => 'Too late', 'due_on' => '2026-11-01'], $owner))
            ->toThrow(ValidationException::class)
            ->and(fn () => $planning->createSprint($otherProject, ['name' => 'Too late', 'starts_on' => '2026-11-01', 'ends_on' => '2026-11-07'], $owner))
            ->toThrow(ValidationException::class);
    });
});

it('keeps project membership, task assignment, and watchers tenant-safe and consistent', function () {
    $tenant = $this->createTenant('projects-members');
    $foreignTenant = $this->createTenant('projects-members-foreign');
    $owner = $this->membershipOf($tenant, $this->ownerOf($tenant));
    $member = $this->membershipOf($tenant, $this->addMember($tenant));
    $foreign = $this->membershipOf($foreignTenant, $this->ownerOf($foreignTenant));

    $this->inTenant($tenant, function () use ($owner, $member, $foreign): void {
        $projects = app(ProjectManager::class);
        $tasks = app(TaskManager::class);
        $project = $projects->create(['name' => 'Members'], $owner, $owner);
        $task = $tasks->create($project, ['title' => 'Assigned task'], $owner, $owner);

        $projects->addMember($project, $member, 'contributor', 60, $owner);
        $tasks->assign($task, [$member->id => 60], $owner);
        $tasks->watch($task, $member);
        $tasks->watch($task, $member);

        expect(ProjectMember::query()->where('project_id', $project->id)->where('membership_id', $member->id)->sole()->allocation_percent)->toBe(60)
            ->and(TaskAssignee::query()->where('task_id', $task->id)->where('membership_id', $member->id)->exists())->toBeTrue()
            ->and(TaskWatcher::query()->where('task_id', $task->id)->where('membership_id', $member->id)->count())->toBe(1)
            ->and(fn () => $projects->addMember($project, $member, 'member', 101, $owner))->toThrow(ValidationException::class)
            ->and(fn () => $projects->addMember($project, $foreign, 'member', 100, $owner))->toThrow(ValidationException::class)
            ->and(fn () => $projects->removeMember($project, $owner, $owner))->toThrow(ValidationException::class);

        $member->update(['status' => MembershipStatus::Suspended]);
        $projects->removeMember($project, $member, $owner);

        $deletedTask = $tasks->create($project, ['title' => 'Deleted task'], $owner, $owner);
        $deletedTask->delete();

        expect(ProjectMember::query()->where('project_id', $project->id)->where('membership_id', $member->id)->exists())->toBeFalse()
            ->and(TaskAssignee::query()->where('task_id', $task->id)->where('membership_id', $member->id)->exists())->toBeFalse()
            ->and(TaskWatcher::query()->where('task_id', $task->id)->where('membership_id', $member->id)->exists())->toBeFalse()
            ->and(fn () => $tasks->assign($deletedTask, [$owner->id => 100], $owner))
            ->toThrow(ModelNotFoundException::class);
    });
});

it('revalidates fresh locked schedules and rejects invalid direct task intervals', function () {
    $tenant = $this->createTenant('projects-locked-schedules');
    $owner = $this->membershipOf($tenant, $this->ownerOf($tenant));

    $this->inTenant($tenant, function () use ($owner): void {
        $projects = app(ProjectManager::class);
        $tasks = app(TaskManager::class);
        $project = $projects->create(['name' => 'Locked dates', 'starts_on' => '2026-10-01', 'due_on' => '2026-10-10'], $owner, $owner);
        $staleProject = Project::query()->findOrFail($project->id);
        $project->update(['due_on' => '2026-10-02']);

        expect(fn () => $projects->update($staleProject, ['starts_on' => '2026-10-05'], $owner))
            ->toThrow(ValidationException::class)
            ->and(fn () => $tasks->create($project, [
                'title' => 'Invalid interval',
                'starts_at' => '2026-10-10 12:00:00 UTC',
                'due_at' => '2026-10-10 11:00:00 UTC',
            ], $owner, $owner))->toThrow(ValidationException::class);

        $task = $tasks->create($project, [
            'title' => 'Fresh task dates',
            'starts_at' => '2026-10-10 09:00:00 UTC',
            'due_at' => '2026-10-10 15:00:00 UTC',
        ], $owner, $owner);
        $staleTask = Task::query()->findOrFail($task->id);
        $task->update(['due_at' => '2026-10-10 10:00:00 UTC']);

        expect(fn () => $tasks->update($staleTask, ['starts_at' => '2026-10-10 11:00:00 UTC'], $owner))
            ->toThrow(ValidationException::class);
    });
});

it('creates recurring tasks and emits reminders once per due occurrence', function () {
    Carbon::setTestNow('2026-10-10 10:00:00 UTC');
    Event::fake([TaskReminderDue::class]);

    try {
        $tenant = $this->createTenant('projects-scheduled', ['timezone' => 'America/Los_Angeles']);
        $owner = $this->membershipOf($tenant, $this->ownerOf($tenant));

        $this->inTenant($tenant, function () use ($owner): void {
            $project = app(ProjectManager::class)->create(['name' => 'Recurring work'], $owner, $owner);
            $tasks = app(TaskManager::class);
            $source = $tasks->create($project, [
                'title' => 'Weekly report',
                'recurrence_rule' => ['frequency' => 'weekly', 'interval' => 1],
                'next_recurrence_at' => now()->subMinute(),
                'due_at' => now()->addDay(),
                'remind_at' => now()->subMinute(),
            ], $owner, $owner);

            expect($tasks->generateDueRecurrences())->toBe(1)
                ->and($tasks->generateDueRecurrences())->toBe(0)
                ->and(Task::query()->where('project_id', $project->id)->count())->toBe(2)
                ->and(Task::query()->whereNotNull('automation_key')->sole()->automation_key)
                ->toBe('recurrence:'.$source->id.':20261010095900');

            $boundary = $tasks->create($project, [
                'title' => 'Local final-day recurrence',
                'recurrence_rule' => ['frequency' => 'daily', 'interval' => 1, 'until' => '2026-10-10'],
                'next_recurrence_at' => Carbon::parse('2026-10-10 06:30:00 UTC'),
                'due_at' => Carbon::parse('2026-10-10 06:30:00 UTC'),
            ], $owner, $owner);

            expect($tasks->generateDueRecurrences())->toBe(1);

            $boundaryClone = Task::query()->where('automation_key', 'recurrence:'.$boundary->id.':20261010063000')->sole();

            expect($boundaryClone->next_recurrence_at?->toIso8601String())->toBe('2026-10-11T06:30:00+00:00');

            Carbon::setTestNow('2026-10-11 06:31:00 UTC');

            expect($tasks->generateDueRecurrences())->toBe(0)
                ->and($boundaryClone->refresh()->next_recurrence_at)->toBeNull()
                ->and(Task::query()->where('automation_key', 'like', 'recurrence:'.$boundary->id.':%')->count())->toBe(1);

            expect($tasks->emitDueReminders())->toBe(1)
                ->and($tasks->emitDueReminders())->toBe(0)
                ->and($source->refresh()->reminder_sent_at)->not->toBeNull()
                ->and(ActivityHistory::query()->where('event', 'projects.task.reminder_due')->count())->toBe(1);
        });

        Event::assertDispatchedTimes(TaskReminderDue::class, 1);
    } finally {
        Carbon::setTestNow();
    }
});

it('keeps recurrence and reminder wall times stable across daylight-saving changes', function () {
    Carbon::setTestNow('2026-03-07 17:01:00 UTC');

    try {
        $tenant = $this->createTenant('projects-recurring-dst', ['timezone' => 'America/Los_Angeles']);
        $owner = $this->membershipOf($tenant, $this->ownerOf($tenant));

        $this->inTenant($tenant, function () use ($owner): void {
            $project = app(ProjectManager::class)->create(['name' => 'DST recurrence'], $owner, $owner);
            $tasks = app(TaskManager::class);
            $source = $tasks->create($project, [
                'title' => 'Daily stand-up',
                'recurrence_rule' => ['frequency' => 'daily', 'interval' => 1],
                'next_recurrence_at' => Carbon::parse('2026-03-07 17:00:00 UTC'),
                'due_at' => Carbon::parse('2026-03-07 17:00:00 UTC'),
                'remind_at' => Carbon::parse('2026-03-07 16:00:00 UTC'),
            ], $owner, $owner);

            expect($tasks->generateDueRecurrences())->toBe(1);

            $second = Task::query()->where('automation_key', 'recurrence:'.$source->id.':20260307170000')->sole();

            expect($second->due_at?->toIso8601String())->toBe('2026-03-08T16:00:00+00:00')
                ->and($second->remind_at?->toIso8601String())->toBe('2026-03-08T15:00:00+00:00')
                ->and($second->next_recurrence_at?->toIso8601String())->toBe('2026-03-08T16:00:00+00:00');

            Carbon::setTestNow('2026-03-08 16:01:00 UTC');

            expect($tasks->generateDueRecurrences())->toBe(1);

            $third = Task::query()->where('automation_key', 'recurrence:'.$second->id.':20260308160000')->sole();

            expect($third->due_at?->toIso8601String())->toBe('2026-03-09T16:00:00+00:00')
                ->and($third->remind_at?->toIso8601String())->toBe('2026-03-09T15:00:00+00:00')
                ->and($third->next_recurrence_at?->toIso8601String())->toBe('2026-03-09T16:00:00+00:00');
        });
    } finally {
        Carbon::setTestNow();
    }
});

it('isolates unowned and inactive-owned recurrences and suppresses cancelled project reminders', function () {
    Carbon::setTestNow('2026-10-11 10:00:00 UTC');
    Event::fake([TaskReminderDue::class]);

    try {
        $tenant = $this->createTenant('projects-recurring-owner-isolation', ['timezone' => 'UTC']);
        $owner = $this->membershipOf($tenant, $this->ownerOf($tenant));
        $member = $this->membershipOf($tenant, $this->addMember($tenant));

        $this->inTenant($tenant, function () use ($owner, $member): void {
            $projects = app(ProjectManager::class);
            $project = $projects->create(['name' => 'Recurring owner isolation'], $owner, $owner);
            $tasks = app(TaskManager::class);
            $inactiveOwned = $tasks->create($project, [
                'title' => 'Suspended owner recurrence',
                'recurrence_rule' => ['frequency' => 'daily', 'interval' => 1],
                'next_recurrence_at' => now()->subMinute(),
                'due_at' => now()->subMinute(),
                'remind_at' => now()->subMinute(),
            ], $member, $owner);
            $unowned = $tasks->create($project, [
                'title' => 'Unowned recurrence',
                'recurrence_rule' => ['frequency' => 'daily', 'interval' => 1],
                'next_recurrence_at' => now()->subMinute(),
                'due_at' => now()->subMinute(),
            ]);

            $member->update(['status' => MembershipStatus::Suspended]);

            expect($tasks->generateDueRecurrences())->toBe(2)
                ->and(Task::query()->whereNotNull('automation_key')->count())->toBe(2)
                ->and(Task::query()->whereNotNull('automation_key')->whereNotNull('owner_id')->exists())->toBeFalse();

            $projects->changeStatus($project, ProjectStatus::Cancelled, $owner);

            expect($tasks->emitDueReminders())->toBe(0)
                ->and($inactiveOwned->refresh()->reminder_sent_at)->toBeNull()
                ->and($unowned->refresh()->owner_id)->toBeNull();
        });

        Event::assertNotDispatched(TaskReminderDue::class);
    } finally {
        Carbon::setTestNow();
    }
});
