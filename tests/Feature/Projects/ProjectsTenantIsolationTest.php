<?php

declare(strict_types=1);

use App\Core\Contacts\Models\Contact;
use App\Modules\Projects\Enums\ApprovalEntity;
use App\Modules\Projects\Enums\ApprovalStatus;
use App\Modules\Projects\Enums\ClientAccessStatus;
use App\Modules\Projects\Enums\EntryStatus;
use App\Modules\Projects\Enums\MilestoneStatus;
use App\Modules\Projects\Enums\Priority;
use App\Modules\Projects\Enums\ProjectStatus;
use App\Modules\Projects\Enums\SprintStatus;
use App\Modules\Projects\Enums\TaskStatus;
use App\Modules\Projects\Models\ActivityHistory;
use App\Modules\Projects\Models\Approval;
use App\Modules\Projects\Models\Attachment;
use App\Modules\Projects\Models\AutomationActionReceipt;
use App\Modules\Projects\Models\ChecklistItem;
use App\Modules\Projects\Models\ClientAccess;
use App\Modules\Projects\Models\CommentMention;
use App\Modules\Projects\Models\Expense;
use App\Modules\Projects\Models\Milestone;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Models\ProjectCategory;
use App\Modules\Projects\Models\ProjectMember;
use App\Modules\Projects\Models\ProjectTemplate;
use App\Modules\Projects\Models\Sprint;
use App\Modules\Projects\Models\Task;
use App\Modules\Projects\Models\TaskAssignee;
use App\Modules\Projects\Models\TaskComment;
use App\Modules\Projects\Models\TaskDependency;
use App\Modules\Projects\Models\TaskWatcher;
use App\Modules\Projects\Models\TimeEntry;

it('isolates every Projects table by workspace', function () {
    $project = function ($tenant): Project {
        $owner = $this->membershipOf($tenant, $this->ownerOf($tenant));
        $project = new Project([
            'code' => 'PRJ-'.str()->upper(str()->random(8)),
            'name' => 'Project '.str()->random(8),
            'status' => ProjectStatus::Active,
            'priority' => Priority::Normal,
            'currency' => 'BDT',
            'budget_amount' => 100_000,
            'progress' => 0,
        ]);
        $project->assignOwner($owner)->save();

        return $project;
    };
    $task = function ($tenant, ?Project $parentProject = null) use ($project): Task {
        $owner = $this->membershipOf($tenant, $this->ownerOf($tenant));
        $task = new Task([
            'project_id' => ($parentProject ?? $project($tenant))->id,
            'title' => 'Task '.str()->random(8),
            'status' => TaskStatus::Todo,
            'priority' => Priority::Normal,
        ]);
        $task->assignOwner($owner)->save();

        return $task;
    };

    $this->assertTenantIsolated(ProjectCategory::class, fn () => ProjectCategory::query()->create([
        'name' => 'Category '.str()->random(8),
    ]));
    $this->assertTenantIsolated(ProjectTemplate::class, fn () => ProjectTemplate::query()->create([
        'name' => 'Template '.str()->random(8),
        'task_blueprints' => [],
    ]));
    $this->assertTenantIsolated(Project::class, fn ($tenant) => $project($tenant));
    $this->assertTenantIsolated(ProjectMember::class, fn ($tenant) => ProjectMember::query()->create([
        'project_id' => $project($tenant)->id,
        'membership_id' => $this->membershipOf($tenant, $this->ownerOf($tenant))->id,
        'role' => 'manager',
        'allocation_percent' => 100,
    ]));
    $this->assertTenantIsolated(Sprint::class, fn ($tenant) => Sprint::query()->create([
        'project_id' => $project($tenant)->id,
        'name' => 'Sprint '.str()->random(8),
        'status' => SprintStatus::Planned,
        'starts_on' => '2026-10-10',
        'ends_on' => '2026-10-24',
    ]));
    $this->assertTenantIsolated(Milestone::class, fn ($tenant) => Milestone::query()->create([
        'project_id' => $project($tenant)->id,
        'name' => 'Milestone '.str()->random(8),
        'due_on' => '2026-10-24',
        'status' => MilestoneStatus::Open,
    ]));
    $this->assertTenantIsolated(Task::class, fn ($tenant) => $task($tenant));
    $this->assertTenantIsolated(TaskAssignee::class, fn ($tenant) => TaskAssignee::query()->create([
        'task_id' => $task($tenant)->id,
        'membership_id' => $this->membershipOf($tenant, $this->ownerOf($tenant))->id,
        'allocation_percent' => 100,
        'assigned_at' => now(),
    ]));
    $this->assertTenantIsolated(TaskWatcher::class, fn ($tenant) => TaskWatcher::query()->create([
        'task_id' => $task($tenant)->id,
        'membership_id' => $this->membershipOf($tenant, $this->ownerOf($tenant))->id,
        'created_at' => now(),
    ]));
    $this->assertTenantIsolated(TaskDependency::class, function ($tenant) use ($task): TaskDependency {
        $dependency = $task($tenant);
        $dependent = $task($tenant);

        return TaskDependency::query()->create([
            'task_id' => $dependent->id,
            'depends_on_task_id' => $dependency->id,
            'type' => 'finish_to_start',
        ]);
    });
    $this->assertTenantIsolated(ChecklistItem::class, fn ($tenant) => ChecklistItem::query()->create([
        'task_id' => $task($tenant)->id,
        'title' => 'Checklist item',
        'position' => 0,
    ]));
    $this->assertTenantIsolated(TaskComment::class, fn ($tenant) => TaskComment::query()->create([
        'task_id' => $task($tenant)->id,
        'author_id' => $this->membershipOf($tenant, $this->ownerOf($tenant))->id,
        'body' => 'Tenant-private comment',
    ]));
    $this->assertTenantIsolated(CommentMention::class, function ($tenant) use ($task): CommentMention {
        $member = $this->membershipOf($tenant, $this->ownerOf($tenant));
        $comment = TaskComment::query()->create([
            'task_id' => $task($tenant)->id,
            'author_id' => $member->id,
            'body' => 'Mentioned comment',
        ]);

        return CommentMention::query()->create([
            'comment_id' => $comment->id,
            'membership_id' => $member->id,
            'created_at' => now(),
        ]);
    });
    $this->assertTenantIsolated(Attachment::class, fn () => Attachment::query()->create([
        'entity_type' => 'task',
        'entity_id' => (string) str()->ulid(),
        'original_name' => 'brief.txt',
        'path' => 'projects/brief.txt',
        'mime_type' => 'text/plain',
        'size' => 12,
    ]));
    $this->assertTenantIsolated(TimeEntry::class, function ($tenant) use ($project, $task): TimeEntry {
        $project = $project($tenant);
        $entryTask = $task($tenant, $project);

        return TimeEntry::query()->create([
            'project_id' => $project->id,
            'task_id' => $entryTask->id,
            'membership_id' => $this->membershipOf($tenant, $this->ownerOf($tenant))->id,
            'worked_on' => '2026-10-10',
            'minutes' => 60,
            'currency' => 'BDT',
            'billable_rate_amount' => 10_000,
            'cost_rate_amount' => 5_000,
            'status' => EntryStatus::Draft,
        ]);
    });
    $this->assertTenantIsolated(Expense::class, function ($tenant) use ($project): Expense {
        $member = $this->membershipOf($tenant, $this->ownerOf($tenant));

        return Expense::query()->create([
            'project_id' => $project($tenant)->id,
            'description' => 'Travel',
            'incurred_on' => '2026-10-10',
            'currency' => 'BDT',
            'amount' => 1_500,
            'status' => EntryStatus::Submitted,
            'submitted_by' => $member->id,
        ]);
    });
    $this->assertTenantIsolated(Approval::class, function ($tenant) use ($project, $task): Approval {
        $member = $this->membershipOf($tenant, $this->ownerOf($tenant));
        $project = $project($tenant);
        $approvalTask = $task($tenant, $project);

        return Approval::query()->create([
            'project_id' => $project->id,
            'entity_type' => ApprovalEntity::Task,
            'entity_id' => $approvalTask->id,
            'status' => ApprovalStatus::Pending,
            'requested_by' => $member->id,
            'approver_id' => $member->id,
        ]);
    });
    $this->assertTenantIsolated(ClientAccess::class, function ($tenant) use ($project): ClientAccess {
        $contact = Contact::factory()->create();

        return ClientAccess::query()->create([
            'project_id' => $project($tenant)->id,
            'contact_id' => $contact->id,
            'status' => ClientAccessStatus::Invited,
            'token_hash' => hash('sha256', (string) str()->ulid()),
        ]);
    });
    $this->assertTenantIsolated(ActivityHistory::class, fn ($tenant) => ActivityHistory::query()->create([
        'project_id' => $project($tenant)->id,
        'event' => 'projects.test',
        'summary' => 'Test event',
        'occurred_at' => now(),
        'created_at' => now(),
    ]));
    $this->assertTenantIsolated(AutomationActionReceipt::class, fn () => AutomationActionReceipt::query()->create([
        'action' => 'projects.test',
        'idempotency_key' => (string) str()->ulid(),
        'output' => ['ok' => true],
    ]));
});
