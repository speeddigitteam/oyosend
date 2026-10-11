<?php

declare(strict_types=1);

use App\Core\Automation\Contracts\TriggerDefinition;
use App\Core\Events\DomainEvent;
use App\Core\Tenancy\Models\Tenant;
use App\Modules\Projects\Automation\Triggers\MilestoneCompletedTrigger;
use App\Modules\Projects\Automation\Triggers\ProjectCreatedTrigger;
use App\Modules\Projects\Automation\Triggers\TaskCompletedTrigger;
use App\Modules\Projects\Automation\Triggers\TaskCreatedTrigger;
use App\Modules\Projects\Events\MilestoneCompleted;
use App\Modules\Projects\Events\ProjectCreated;
use App\Modules\Projects\Events\TaskCompleted;
use App\Modules\Projects\Events\TaskCreated;
use App\Modules\Projects\Events\TaskReminderDue;
use App\Modules\Projects\Events\TimeEntryApproved;
use Stancl\Tenancy\Tenancy;

beforeEach(function () {
    $this->tenancy = app(Tenancy::class);
    $this->previousTenant = $this->tenancy->tenant;
    $this->previousTenancyState = $this->tenancy->initialized;

    $tenant = new Tenant;
    $tenant->setAttribute($tenant->getTenantKeyName(), 'tenant-project-events');
    $this->tenancy->tenant = $tenant;
    $this->tenancy->initialized = true;
});

afterEach(function () {
    $this->tenancy->tenant = $this->previousTenant;
    $this->tenancy->initialized = $this->previousTenancyState;
});

it('keeps every Projects event name and scalar payload stable', function () {
    $events = [
        [
            new ProjectCreated('project-1', 'Website launch', 'contact-1', null, 'membership-1'),
            'projects.project.created',
            ['project_id' => 'project-1', 'name' => 'Website launch', 'contact_id' => 'contact-1', 'company_id' => null, 'owner_id' => 'membership-1'],
        ],
        [
            new TaskCreated('task-1', 'project-1', 'Prepare brief', 'membership-2'),
            'projects.task.created',
            ['task_id' => 'task-1', 'project_id' => 'project-1', 'title' => 'Prepare brief', 'owner_id' => 'membership-2'],
        ],
        [
            new TaskCompleted('task-1', 'project-1', 'Prepare brief'),
            'projects.task.completed',
            ['task_id' => 'task-1', 'project_id' => 'project-1', 'title' => 'Prepare brief'],
        ],
        [
            new MilestoneCompleted('milestone-1', 'project-1', 'Design approved'),
            'projects.milestone.completed',
            ['milestone_id' => 'milestone-1', 'project_id' => 'project-1', 'name' => 'Design approved'],
        ],
        [
            new TimeEntryApproved('time-1', 'project-1', 'membership-2', 90, true, 12_500, 8_000, 'BDT'),
            'projects.time_entry.approved',
            ['time_entry_id' => 'time-1', 'project_id' => 'project-1', 'membership_id' => 'membership-2', 'minutes' => 90, 'is_billable' => true, 'billable_rate_amount' => 12_500, 'cost_rate_amount' => 8_000, 'currency' => 'BDT'],
        ],
        [
            new TaskReminderDue('task-2', 'project-1', 'Send review reminder', null),
            'projects.task.reminder_due',
            ['task_id' => 'task-2', 'project_id' => 'project-1', 'title' => 'Send review reminder', 'owner_id' => null],
        ],
    ];

    foreach ($events as [$event, $name, $payload]) {
        expect($event)->toBeInstanceOf(DomainEvent::class)
            ->and($event::name())->toBe($name)
            ->and($event->tenantId())->toBe('tenant-project-events')
            ->and($event->payload())->toBe($payload)
            ->and(array_filter(
                $event->payload(),
                static fn (mixed $value): bool => $value !== null && ! is_scalar($value),
            ))->toBe([], $event::class.' has a non-scalar payload value');
    }
});

it('keeps automation trigger keys and output schemas aligned with their events', function () {
    $contracts = [
        ProjectCreatedTrigger::class => new ProjectCreated('project-2', 'Office move', null, 'company-1', 'membership-3'),
        TaskCreatedTrigger::class => new TaskCreated('task-3', 'project-2', 'Book movers', null),
        TaskCompletedTrigger::class => new TaskCompleted('task-3', 'project-2', 'Book movers'),
        MilestoneCompletedTrigger::class => new MilestoneCompleted('milestone-2', 'project-2', 'Move complete'),
    ];

    foreach ($contracts as $trigger => $event) {
        expect(is_subclass_of($trigger, TriggerDefinition::class))->toBeTrue();

        $schema = $trigger::outputSchema();
        $properties = $schema['properties'];
        $required = $schema['required'];
        $nonNullable = array_keys(array_filter(
            $properties,
            static fn (array $property): bool => ! in_array('null', (array) $property['type'], true),
        ));

        expect($trigger::event())->toBe($event::class)
            ->and($trigger::key())->toBe($event::name())
            ->and($schema['type'])->toBe('object')
            ->and(array_keys($properties))->toBe(array_keys($event->payload()))
            ->and($required)->toBe($nonNullable);

        foreach ($event->payload() as $key => $value) {
            $actualType = match (true) {
                $value === null => 'null',
                is_string($value) => 'string',
                is_int($value) => 'integer',
                is_bool($value) => 'boolean',
                default => 'unsupported',
            };

            expect((array) $properties[$key]['type'])->toContain($actualType);
        }
    }
});
