<?php

declare(strict_types=1);

use App\Core\Contacts\Models\Contact;
use App\Core\Tenancy\Storage\TenantStorage;
use App\Modules\Projects\Actions\ApprovalManager;
use App\Modules\Projects\Actions\ClientAccessManager;
use App\Modules\Projects\Actions\CollaborationManager;
use App\Modules\Projects\Actions\PlanningManager;
use App\Modules\Projects\Actions\ProjectManager;
use App\Modules\Projects\Actions\TaskManager;
use App\Modules\Projects\Actions\TimeExpenseManager;
use App\Modules\Projects\Enums\ApprovalEntity;
use App\Modules\Projects\Models\Approval;
use App\Modules\Projects\Models\Attachment;
use App\Modules\Projects\Models\ChecklistItem;
use App\Modules\Projects\Models\ClientAccess;
use App\Modules\Projects\Models\Expense;
use App\Modules\Projects\Models\Milestone;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Models\Sprint;
use App\Modules\Projects\Models\Task;
use App\Modules\Projects\Models\TimeEntry;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Assert;

it('rejects representative foreign and mismatched Projects route bindings without mutation', function () {
    Storage::fake((string) config('tenancy.filesystem.disk', 'local'));

    $home = $this->createTenant('projects-route-isolation-home');
    $intruder = $this->createTenant('projects-route-isolation-intruder');
    $this->entitle($home, 'projects');
    $this->entitle($intruder, 'projects');

    $homeOwner = $this->membershipOf($home, $this->ownerOf($home));
    $intruderOwner = $this->membershipOf($intruder, $this->ownerOf($intruder));
    $homeRequester = $this->membershipOf($home, $this->addMember($home, $this->createRole($home, 'Route approvers', ['projects.approvals.decide'])));
    $intruderRequester = $this->membershipOf($intruder, $this->addMember($intruder, $this->createRole($intruder, 'Route approvers', ['projects.approvals.decide'])));

    $records = function ($tenant, $owner, $requester, string $prefix): array {
        return $this->inTenant($tenant, function () use ($owner, $requester, $prefix): array {
            $projects = app(ProjectManager::class);
            $tasks = app(TaskManager::class);
            $planning = app(PlanningManager::class);
            $collaboration = app(CollaborationManager::class);
            $finance = app(TimeExpenseManager::class);
            $clientAccess = app(ClientAccessManager::class);

            $project = $projects->create(['name' => "{$prefix} project"], $owner, $owner);
            $otherProject = $projects->create(['name' => "{$prefix} other project"], $owner, $owner);
            $task = $tasks->create($project, ['title' => "{$prefix} task"], $owner, $owner);
            $otherTask = $tasks->create($otherProject, ['title' => "{$prefix} other task"], $owner, $owner);
            $milestone = $planning->createMilestone($project, ['name' => "{$prefix} milestone", 'due_on' => '2026-11-01'], $owner);
            $otherMilestone = $planning->createMilestone($otherProject, ['name' => "{$prefix} other milestone", 'due_on' => '2026-11-02'], $owner);
            $sprint = $planning->createSprint($project, ['name' => "{$prefix} sprint", 'starts_on' => '2026-10-12', 'ends_on' => '2026-10-19'], $owner);
            $otherSprint = $planning->createSprint($otherProject, ['name' => "{$prefix} other sprint", 'starts_on' => '2026-10-20', 'ends_on' => '2026-10-27'], $owner);
            $checklist = $collaboration->addChecklist($task, "{$prefix} checklist", 0, $owner);
            $otherChecklist = $collaboration->addChecklist($otherTask, "{$prefix} other checklist", 0, $owner);

            $downloadAttachment = $collaboration->attachProject(
                $project,
                UploadedFile::fake()->create("{$prefix}-download.txt", 1, 'text/plain'),
                $owner,
            );
            $deleteAttachment = $collaboration->attachProject(
                $project,
                UploadedFile::fake()->create("{$prefix}-delete.txt", 1, 'text/plain'),
                $owner,
            );
            $otherAttachment = $collaboration->attachProject(
                $otherProject,
                UploadedFile::fake()->create("{$prefix}-other.txt", 1, 'text/plain'),
                $owner,
            );

            $contact = Contact::factory()->create(['first_name' => $prefix, 'last_name' => 'Client']);
            $otherContact = Contact::factory()->create(['first_name' => $prefix, 'last_name' => 'Other']);
            $access = $clientAccess->grant($project, $contact, null, true, false, null, $owner)['access'];
            $otherAccess = $clientAccess->grant($otherProject, $otherContact, null, true, false, null, $owner)['access'];
            $timeEntry = $finance->logTime($project, $owner, ['worked_on' => '2026-10-11', 'minutes' => 30], $owner);
            $expense = $finance->createExpense($project, $owner, ['description' => "{$prefix} taxi", 'incurred_on' => '2026-10-11', 'amount' => 1_250]);
            $approval = app(ApprovalManager::class)->request($project, ApprovalEntity::Task, $task->id, $requester, $owner);
            $cancelApproval = app(ApprovalManager::class)->request($otherProject, ApprovalEntity::Task, $otherTask->id, $owner, $requester);

            return compact(
                'project',
                'otherProject',
                'task',
                'otherTask',
                'milestone',
                'otherMilestone',
                'sprint',
                'otherSprint',
                'checklist',
                'otherChecklist',
                'downloadAttachment',
                'deleteAttachment',
                'otherAttachment',
                'access',
                'otherAccess',
                'timeEntry',
                'expense',
                'approval',
                'cancelApproval',
            );
        });
    };

    $homeRecords = $records($home, $homeOwner, $homeRequester, 'Home');
    $intruderRecords = $records($intruder, $intruderOwner, $intruderRequester, 'Intruder');

    $request = function ($tenant, string $method, string $path, array $data = []) {
        return $this->startNewBrowserSession()
            ->actingAsOwner($tenant)
            ->call($method, $this->tenantUrl($tenant, $path), $data);
    };

    $project = $homeRecords['project'];
    $otherProject = $homeRecords['otherProject'];
    $intruderProject = $intruderRecords['project'];

    $projectState = fn (): array => $this->inTenant(
        $home,
        fn (): array => [Project::query()->findOrFail($project->id)->status->value],
    );
    $before = $projectState();
    $request($intruder, 'GET', "/projects/{$project->id}")->assertNotFound();
    Assert::assertSame($before, $projectState(), 'Foreign project show mutated the project.');
    $request($home, 'GET', "/projects/{$project->id}")->assertOk();

    $attachmentState = fn (): array => $this->inTenant($home, function () use ($homeRecords): array {
        $storage = app(TenantStorage::class);

        return [
            Attachment::query()->whereKey($homeRecords['downloadAttachment']->id)->exists(),
            Attachment::query()->whereKey($homeRecords['deleteAttachment']->id)->exists(),
            Attachment::query()->whereKey($homeRecords['otherAttachment']->id)->exists(),
            $storage->exists($homeRecords['deleteAttachment']->path),
        ];
    });
    $before = $attachmentState();
    $request($intruder, 'GET', "/projects/{$intruderProject->id}/attachments/{$homeRecords['downloadAttachment']->id}")->assertNotFound();
    Assert::assertSame($before, $attachmentState(), 'Foreign attachment download mutated attachment state.');
    $request($home, 'GET', "/projects/{$project->id}/attachments/{$homeRecords['otherAttachment']->id}")->assertNotFound();
    Assert::assertSame($before, $attachmentState(), 'Mismatched attachment download mutated attachment state.');
    $request($home, 'GET', "/projects/{$project->id}/attachments/{$homeRecords['downloadAttachment']->id}")->assertOk();

    $cases = [
        [
            'project status',
            'PATCH',
            "/projects/{$project->id}/status",
            null,
            "/projects/{$project->id}/status",
            ['status' => 'active'],
            $projectState,
            ['planned'],
            ['active'],
        ],
        [
            'task status',
            'PATCH',
            "/projects/{$intruderProject->id}/tasks/{$homeRecords['task']->id}/status",
            "/projects/{$project->id}/tasks/{$homeRecords['otherTask']->id}/status",
            "/projects/{$project->id}/tasks/{$homeRecords['task']->id}/status",
            ['status' => 'in_progress'],
            fn (): array => $this->inTenant($home, fn (): array => [
                Task::query()->findOrFail($homeRecords['task']->id)->status->value,
                Task::query()->findOrFail($homeRecords['otherTask']->id)->status->value,
            ]),
            ['backlog', 'backlog'],
            ['in_progress', 'backlog'],
        ],
        [
            'milestone status',
            'PATCH',
            "/projects/{$intruderProject->id}/milestones/{$homeRecords['milestone']->id}/status",
            "/projects/{$project->id}/milestones/{$homeRecords['otherMilestone']->id}/status",
            "/projects/{$project->id}/milestones/{$homeRecords['milestone']->id}/status",
            ['status' => 'cancelled'],
            fn (): array => $this->inTenant($home, fn (): array => [
                Milestone::query()->findOrFail($homeRecords['milestone']->id)->status->value,
                Milestone::query()->findOrFail($homeRecords['otherMilestone']->id)->status->value,
            ]),
            ['open', 'open'],
            ['cancelled', 'open'],
        ],
        [
            'sprint status',
            'PATCH',
            "/projects/{$intruderProject->id}/sprints/{$homeRecords['sprint']->id}/status",
            "/projects/{$project->id}/sprints/{$homeRecords['otherSprint']->id}/status",
            "/projects/{$project->id}/sprints/{$homeRecords['sprint']->id}/status",
            ['status' => 'active'],
            fn (): array => $this->inTenant($home, fn (): array => [
                Sprint::query()->findOrFail($homeRecords['sprint']->id)->status->value,
                Sprint::query()->findOrFail($homeRecords['otherSprint']->id)->status->value,
            ]),
            ['planned', 'planned'],
            ['active', 'planned'],
        ],
        [
            'checklist update',
            'PATCH',
            "/projects/{$intruderProject->id}/tasks/{$intruderRecords['task']->id}/checklists/{$homeRecords['checklist']->id}",
            "/projects/{$project->id}/tasks/{$homeRecords['task']->id}/checklists/{$homeRecords['otherChecklist']->id}",
            "/projects/{$project->id}/tasks/{$homeRecords['task']->id}/checklists/{$homeRecords['checklist']->id}",
            ['completed' => true],
            fn (): array => $this->inTenant($home, fn (): array => [
                ChecklistItem::query()->findOrFail($homeRecords['checklist']->id)->is_completed,
                ChecklistItem::query()->findOrFail($homeRecords['otherChecklist']->id)->is_completed,
            ]),
            [false, false],
            [true, false],
        ],
        [
            'attachment delete',
            'DELETE',
            "/projects/{$intruderProject->id}/attachments/{$homeRecords['deleteAttachment']->id}",
            "/projects/{$project->id}/attachments/{$homeRecords['otherAttachment']->id}",
            "/projects/{$project->id}/attachments/{$homeRecords['deleteAttachment']->id}",
            [],
            $attachmentState,
            [true, true, true, true],
            [true, false, true, false],
        ],
        [
            'client access revoke',
            'DELETE',
            "/projects/{$intruderProject->id}/client-access/{$homeRecords['access']->id}",
            "/projects/{$project->id}/client-access/{$homeRecords['otherAccess']->id}",
            "/projects/{$project->id}/client-access/{$homeRecords['access']->id}",
            [],
            fn (): array => $this->inTenant($home, fn (): array => [
                ClientAccess::query()->findOrFail($homeRecords['access']->id)->status->value,
                ClientAccess::query()->findOrFail($homeRecords['otherAccess']->id)->status->value,
            ]),
            ['invited', 'invited'],
            ['revoked', 'invited'],
        ],
        [
            'time entry submit',
            'PATCH',
            "/project-time/{$homeRecords['timeEntry']->id}/submit",
            null,
            "/project-time/{$homeRecords['timeEntry']->id}/submit",
            [],
            fn (): array => $this->inTenant($home, fn (): array => [TimeEntry::query()->findOrFail($homeRecords['timeEntry']->id)->status->value]),
            ['draft'],
            ['submitted'],
        ],
        [
            'expense submit',
            'PATCH',
            "/project-expenses/{$homeRecords['expense']->id}/submit",
            null,
            "/project-expenses/{$homeRecords['expense']->id}/submit",
            [],
            fn (): array => $this->inTenant($home, fn (): array => [Expense::query()->findOrFail($homeRecords['expense']->id)->status->value]),
            ['draft'],
            ['submitted'],
        ],
        [
            'approval cancel',
            'PATCH',
            "/project-approvals/{$homeRecords['cancelApproval']->id}/cancel",
            null,
            "/project-approvals/{$homeRecords['cancelApproval']->id}/cancel",
            [],
            fn (): array => $this->inTenant($home, fn (): array => [Approval::query()->findOrFail($homeRecords['cancelApproval']->id)->status->value]),
            ['pending'],
            ['cancelled'],
        ],
        [
            'approval decision',
            'PATCH',
            "/project-approvals/{$homeRecords['approval']->id}/decision",
            null,
            "/project-approvals/{$homeRecords['approval']->id}/decision",
            ['approve' => true],
            fn (): array => $this->inTenant($home, fn (): array => [Approval::query()->findOrFail($homeRecords['approval']->id)->status->value]),
            ['pending'],
            ['approved'],
        ],
    ];

    foreach ($cases as [$label, $method, $foreignPath, $mismatchedPath, $controlPath, $data, $state, $initial, $expected]) {
        Assert::assertSame($initial, $state(), "Fixture for [{$label}] did not start in the expected state.");

        $foreign = $request($intruder, $method, $foreignPath, $data);
        Assert::assertSame(404, $foreign->getStatusCode(), "Foreign route [{$label}] did not return 404.");
        Assert::assertSame($initial, $state(), "Foreign route [{$label}] mutated home data.");

        if ($mismatchedPath !== null) {
            $mismatched = $request($home, $method, $mismatchedPath, $data);
            Assert::assertSame(404, $mismatched->getStatusCode(), "Mismatched route [{$label}] did not return 404.");
            Assert::assertSame($initial, $state(), "Mismatched route [{$label}] mutated data.");
        }

        $control = $request($home, $method, $controlPath, $data);
        Assert::assertNotSame(404, $control->getStatusCode(), "Home control for [{$label}] also returned 404.");
        Assert::assertLessThan(500, $control->getStatusCode(), "Home control for [{$label}] failed.");
        Assert::assertSame($expected, $state(), "Home control for [{$label}] did not reach its controller mutation.");
    }

    Assert::assertSame($otherProject->id, $homeRecords['otherTask']->project_id);
});
