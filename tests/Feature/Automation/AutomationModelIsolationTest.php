<?php

declare(strict_types=1);

use App\Core\Tenancy\Models\Tenant;
use App\Modules\Automation\Enums\ApprovalStatus;
use App\Modules\Automation\Enums\RunSource;
use App\Modules\Automation\Enums\RunStatus;
use App\Modules\Automation\Enums\StepStatus;
use App\Modules\Automation\Enums\VersionStatus;
use App\Modules\Automation\Enums\WorkflowStatus;
use App\Modules\Automation\Models\Approval;
use App\Modules\Automation\Models\Run;
use App\Modules\Automation\Models\RunStep;
use App\Modules\Automation\Models\WebhookEndpoint;
use App\Modules\Automation\Models\Workflow;
use App\Modules\Automation\Models\WorkflowVersion;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;

/**
 * @return array{0: Workflow, 1: WorkflowVersion}
 */
function automationWorkflow(): array
{
    $workflow = Workflow::query()->create(['name' => 'W '.Str::random(4), 'status' => WorkflowStatus::Draft]);
    $version = WorkflowVersion::query()->create([
        'workflow_id' => $workflow->id, 'number' => 1, 'status' => VersionStatus::Draft, 'graph' => ['nodes' => [], 'edges' => []],
    ]);

    return [$workflow, $version];
}

function automationRun(): Run
{
    [$workflow, $version] = automationWorkflow();

    return Run::query()->create([
        'workflow_id' => $workflow->id, 'version_id' => $version->id, 'version_number' => 1, 'source' => RunSource::Manual,
        'status' => RunStatus::Queued, 'idempotency_key' => Str::random(20),
    ]);
}

it('isolates workflows by tenant', function () {
    $this->assertTenantIsolated(Workflow::class, fn (Tenant $tenant) => automationWorkflow()[0]);
});

it('isolates workflow versions by tenant', function () {
    $this->assertTenantIsolated(WorkflowVersion::class, fn (Tenant $tenant) => automationWorkflow()[1]);
});

it('isolates runs by tenant', function () {
    $this->assertTenantIsolated(Run::class, fn (Tenant $tenant) => automationRun());
});

it('isolates run steps by tenant', function () {
    $this->assertTenantIsolated(RunStep::class, fn (Tenant $tenant) => RunStep::query()->create([
        'run_id' => automationRun()->id, 'node_id' => 'n1', 'node_type' => 'end', 'status' => StepStatus::Pending,
    ]));
});

it('isolates approvals by tenant', function () {
    $this->assertTenantIsolated(Approval::class, function (Tenant $tenant) {
        $run = automationRun();
        $step = RunStep::query()->create(['run_id' => $run->id, 'node_id' => 'n1', 'node_type' => 'approval', 'status' => StepStatus::Waiting]);

        return Approval::query()->create([
            'run_id' => $run->id, 'run_step_id' => $step->id, 'workflow_id' => $run->workflow_id, 'title' => 'T',
            'approver_type' => 'permission', 'approver_permission' => 'automation.approvals.decide', 'status' => ApprovalStatus::Pending,
        ]);
    });
});

it('isolates webhook endpoints by tenant', function () {
    $this->assertTenantIsolated(WebhookEndpoint::class, fn (Tenant $tenant) => WebhookEndpoint::query()->create([
        'name' => 'Hook '.Str::random(4), 'url' => 'https://hooks.example.test/in',
    ]));
});

it('keeps the same run idempotency key independent per tenant and unique within a workflow', function () {
    $a = $this->createTenant();
    $b = $this->createTenant();

    $make = function (string $key) {
        [$workflow, $version] = automationWorkflow();

        return fn () => Run::query()->create([
            'workflow_id' => $workflow->id, 'version_id' => $version->id, 'version_number' => 1, 'source' => RunSource::Event,
            'status' => RunStatus::Queued, 'idempotency_key' => $key,
        ]);
    };

    $this->inTenant($a, fn () => $make('evt:1')());
    $this->inTenant($b, fn () => $make('evt:1')());

    $this->inTenant($a, function () {
        [$workflow, $version] = automationWorkflow();
        $create = fn () => Run::query()->create([
            'workflow_id' => $workflow->id, 'version_id' => $version->id, 'version_number' => 1, 'source' => RunSource::Event,
            'status' => RunStatus::Queued, 'idempotency_key' => 'same',
        ]);
        $create();

        expect($create)->toThrow(UniqueConstraintViolationException::class);
    });
});
