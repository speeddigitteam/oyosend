<?php

declare(strict_types=1);

use App\Modules\Projects\Actions\ProjectManager;
use App\Modules\Projects\Actions\TaskManager;
use App\Modules\Projects\Automation\Actions\AssignTaskAction;
use App\Modules\Projects\Automation\Actions\CreateProjectAction;
use App\Modules\Projects\Automation\Actions\CreateTaskAction;
use App\Modules\Projects\Models\AutomationActionReceipt;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Models\Task;
use App\Modules\Projects\Models\TaskAssignee;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;

it('creates one project and replays the durable output for an automation retry', function () {
    $tenant = $this->createTenant('projects-create-action');
    $owner = $this->membershipOf($tenant, $this->ownerOf($tenant));

    $this->inTenant($tenant, function () use ($owner): void {
        $action = app(CreateProjectAction::class);
        $input = ['name' => 'Automation project', 'owner_id' => $owner->id];

        $first = $action->execute($input, 'workflow:project:create:1');
        $retry = $action->execute($input, 'workflow:project:create:1');

        expect($retry)->toBe($first)
            ->and(Project::query()->count())->toBe(1)
            ->and(Project::query()->sole()->id)->toBe($first['project_id'])
            ->and(AutomationActionReceipt::query()->sole()->output)->toBe($first);
    });
});

it('rejects an inverted project date range through automation without consuming the retry key', function () {
    $tenant = $this->createTenant('projects-create-action-dates');
    $owner = $this->membershipOf($tenant, $this->ownerOf($tenant));

    $this->inTenant($tenant, function () use ($owner): void {
        $action = app(CreateProjectAction::class);
        $key = 'workflow:project:create:dates';

        expect(fn () => $action->execute([
            'name' => 'Invalid schedule',
            'owner_id' => $owner->id,
            'starts_on' => '2026-11-20',
            'due_on' => '2026-11-19',
        ], $key))->toThrow(ValidationException::class);

        expect(Project::query()->count())->toBe(0)
            ->and(AutomationActionReceipt::query()->count())->toBe(0);

        $output = $action->execute([
            'name' => 'Valid schedule',
            'owner_id' => $owner->id,
            'starts_on' => '2026-11-20',
            'due_on' => '2026-11-21',
        ], $key);

        $project = Project::query()->sole();

        expect($project->id)->toBe($output['project_id'])
            ->and($project->starts_on->toDateString())->toBe('2026-11-20')
            ->and($project->due_on->toDateString())->toBe('2026-11-21')
            ->and(AutomationActionReceipt::query()->sole()->output)->toBe($output);
    });
});

it('rejects blank automation names without consuming retry keys', function () {
    $tenant = $this->createTenant('projects-action-blank-names');
    $owner = $this->membershipOf($tenant, $this->ownerOf($tenant));

    $this->inTenant($tenant, function () use ($owner): void {
        $projects = app(ProjectManager::class);
        $projectAction = app(CreateProjectAction::class);
        $taskAction = app(CreateTaskAction::class);
        $projectKey = 'workflow:project:create:blank';

        expect(fn () => $projects->create(['name' => '   '], $owner, $owner))->toThrow(ValidationException::class)
            ->and(CreateProjectAction::inputSchema()['properties']['name']['minLength'])->toBe(1)
            ->and(CreateTaskAction::inputSchema()['properties']['title']['minLength'])->toBe(1)
            ->and(fn () => $projectAction->execute(['name' => '   ', 'owner_id' => $owner->id], $projectKey))
            ->toThrow(ValidationException::class);

        expect(Project::query()->count())->toBe(0)
            ->and(AutomationActionReceipt::query()->count())->toBe(0);

        $projectOutput = $projectAction->execute(['name' => 'Recovered project', 'owner_id' => $owner->id], $projectKey);
        $project = Project::query()->findOrFail($projectOutput['project_id']);

        expect(fn () => $projects->update($project, ['name' => "\n"], $owner))->toThrow(ValidationException::class)
            ->and($project->refresh()->name)->toBe('Recovered project');

        $taskKey = 'workflow:task:create:blank';

        expect(fn () => $taskAction->execute(['project_id' => $projectOutput['project_id'], 'title' => "\t"], $taskKey))
            ->toThrow(ValidationException::class);

        expect(Task::query()->count())->toBe(0)
            ->and(AutomationActionReceipt::query()->count())->toBe(1);

        $taskOutput = $taskAction->execute(['project_id' => $projectOutput['project_id'], 'title' => 'Recovered task'], $taskKey);

        expect(Task::query()->sole()->id)->toBe($taskOutput['task_id'])
            ->and(AutomationActionReceipt::query()->count())->toBe(2);
    });
});

it('creates one task and replays the durable output for an automation retry', function () {
    $tenant = $this->createTenant('projects-task-action');
    $owner = $this->membershipOf($tenant, $this->ownerOf($tenant));

    $this->inTenant($tenant, function () use ($owner): void {
        $project = app(ProjectManager::class)->create(['name' => 'Action project'], $owner, $owner);
        $action = app(CreateTaskAction::class);
        $input = ['project_id' => $project->id, 'title' => 'Generated task', 'owner_id' => $owner->id];

        $first = $action->execute($input, 'workflow:task:create:1');
        $retry = $action->execute($input, 'workflow:task:create:1');

        expect($retry)->toBe($first)
            ->and(Task::query()->where('project_id', $project->id)->count())->toBe(1)
            ->and(Task::query()->sole()->automation_key)->toBe('workflow:task:create:1')
            ->and(AutomationActionReceipt::query()->sole()->output)->toBe($first);
    });
});

it('does not undo a later assignment when an assign action is replayed', function () {
    $tenant = $this->createTenant('projects-assign-action');
    $owner = $this->membershipOf($tenant, $this->ownerOf($tenant));
    $assignee = $this->membershipOf($tenant, $this->addMember($tenant));
    $laterAssignee = $this->membershipOf($tenant, $this->addMember($tenant));

    $this->inTenant($tenant, function () use ($owner, $assignee, $laterAssignee): void {
        $project = app(ProjectManager::class)->create(['name' => 'Assignments'], $owner, $owner);
        $task = app(TaskManager::class)->create($project, ['title' => 'Assign me'], $owner, $owner);
        $action = app(AssignTaskAction::class);
        $input = ['task_id' => $task->id, 'membership_id' => $assignee->id, 'allocation_percent' => 70];

        $first = $action->execute($input, 'workflow:task:assign:1');
        app(TaskManager::class)->assign($task, [$laterAssignee->id => 80], $owner);
        $retry = $action->execute($input, 'workflow:task:assign:1');

        expect($retry)->toBe($first)
            ->and(TaskAssignee::query()->where('task_id', $task->id)->pluck('membership_id')->all())
            ->toBe([$laterAssignee->id])
            ->and(AutomationActionReceipt::query()->sole()->output)->toBe($first);
    });
});

it('rolls a failed action receipt back so the same key remains retryable', function () {
    $tenant = $this->createTenant('projects-action-rollback');
    $owner = $this->membershipOf($tenant, $this->ownerOf($tenant));

    $this->inTenant($tenant, function () use ($owner): void {
        $project = app(ProjectManager::class)->create(['name' => 'Retry project'], $owner, $owner);
        $action = app(CreateTaskAction::class);
        $key = 'workflow:task:create:retry';

        expect(fn () => $action->execute([
            'project_id' => $project->id,
            'title' => 'Initially invalid',
            'owner_id' => (string) str()->ulid(),
        ], $key))->toThrow(ModelNotFoundException::class);

        expect(AutomationActionReceipt::query()->count())->toBe(0)
            ->and(Task::query()->count())->toBe(0);

        $output = $action->execute([
            'project_id' => $project->id,
            'title' => 'Now valid',
            'owner_id' => $owner->id,
        ], $key);

        expect(Task::query()->sole()->id)->toBe($output['task_id'])
            ->and(AutomationActionReceipt::query()->sole()->output)->toBe($output);
    });
});
