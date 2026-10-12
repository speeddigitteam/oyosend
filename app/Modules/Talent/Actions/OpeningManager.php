<?php

declare(strict_types=1);

namespace App\Modules\Talent\Actions;

use App\Core\Audit\AuditLogger;
use App\Core\Billing\UsageLimits;
use App\Core\Identity\Models\TenantMembership;
use App\Core\Organization\Models\Branch;
use App\Core\Organization\Models\Department;
use App\Core\Tenancy\Models\Tenant;
use App\Core\Tenancy\TenantContext;
use App\Modules\Talent\Enums\ApplicationStatus;
use App\Modules\Talent\Enums\OpeningStatus;
use App\Modules\Talent\Events\OpeningClosed;
use App\Modules\Talent\Events\OpeningPublished;
use App\Modules\Talent\Models\Application;
use App\Modules\Talent\Models\JobOpening;
use App\Modules\Talent\Models\Pipeline;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Job openings: creation, editing and the draft / open / on hold / closed lifecycle. */
final class OpeningManager
{
    public const string LIMIT = 'talent.job_openings';

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly UsageLimits $limits,
        private readonly TenantContext $context,
        private readonly RecruitmentCatalogue $catalogue,
        private readonly EmployeeLookup $employees,
    ) {}

    /** @param array<string, mixed> $attributes */
    public function create(array $attributes, ?TenantMembership $actor): JobOpening
    {
        $values = $this->resolve($attributes, null, $actor);
        $open = ($attributes['status'] ?? 'draft') === OpeningStatus::Open->value;

        return DB::transaction(function () use ($values, $actor, $open): JobOpening {
            $opening = new JobOpening($values);
            $opening->status = OpeningStatus::Draft;
            $opening->created_by = $actor?->id;
            $opening->save();

            if ($open) {
                $opening = $this->changeStatus($opening, OpeningStatus::Open, $actor);
            }

            return $opening;
        });
    }

    /** @param array<string, mixed> $attributes */
    public function update(JobOpening $opening, array $attributes, ?TenantMembership $actor): JobOpening
    {
        return DB::transaction(function () use ($opening, $attributes, $actor): JobOpening {
            /** @var JobOpening $locked */
            $locked = JobOpening::query()->whereKey($opening->id)->lockForUpdate()->firstOrFail();
            $values = $this->resolve($attributes, $locked, $actor);

            $hired = $this->hiredCount($locked);

            if ($values['headcount'] < max(1, $hired)) {
                throw ValidationException::withMessages(['headcount' => __('The headcount cannot be below the number already hired.')]);
            }

            if ($values['pipeline_id'] !== $locked->pipeline_id
                && Application::query()->where('opening_id', $locked->id)->exists()) {
                throw ValidationException::withMessages(['pipeline_id' => __('The pipeline cannot change once there are applications.')]);
            }

            $locked->fill($values)->save();

            return $locked;
        });
    }

    public function changeStatus(JobOpening $opening, OpeningStatus $to, ?TenantMembership $actor = null): JobOpening
    {
        $tenantId = $this->context->requireId();

        return DB::transaction(function () use ($opening, $to, $tenantId): JobOpening {
            /** @var JobOpening $locked */
            $locked = JobOpening::query()->whereKey($opening->id)->lockForUpdate()->firstOrFail();
            $from = $locked->status;

            if ($from === $to) {
                return $locked;
            }

            $allowed = match ($from) {
                OpeningStatus::Draft => [OpeningStatus::Open, OpeningStatus::Closed],
                OpeningStatus::Open => [OpeningStatus::OnHold, OpeningStatus::Closed],
                OpeningStatus::OnHold => [OpeningStatus::Open, OpeningStatus::Closed],
                OpeningStatus::Closed => [OpeningStatus::Open],
            };

            if (! in_array($to, $allowed, true)) {
                throw ValidationException::withMessages(['status' => __('That status change is not allowed from the current status.')]);
            }

            if ($to === OpeningStatus::Open) {
                // Serialize the plan-limit check with the change so two openings cannot both fit.
                Tenant::query()->whereKey($tenantId)->lockForUpdate()->firstOrFail();
                $this->limits->ensure(self::LIMIT, field: 'status');

                if ($from === OpeningStatus::Closed && $this->hiredCount($locked) >= $locked->headcount) {
                    throw ValidationException::withMessages(['status' => __('Every position of this opening is filled. Raise the headcount first.')]);
                }

                $locked->opened_at ??= now();
                $locked->closed_at = null;
            }

            if ($to === OpeningStatus::Closed) {
                $locked->closed_at = now();
            }

            $locked->status = $to;
            $locked->save();
            $this->audit->record('talent.opening.status_changed', $locked, ['from' => $from->value, 'to' => $to->value]);

            if ($to === OpeningStatus::Open) {
                OpeningPublished::dispatch([
                    'opening_id' => $locked->id, 'title' => $locked->title, 'department_id' => $locked->department_id, 'headcount' => $locked->headcount,
                ]);
            } elseif ($to === OpeningStatus::Closed) {
                OpeningClosed::dispatch([
                    'opening_id' => $locked->id, 'title' => $locked->title, 'hired' => $this->hiredCount($locked), 'headcount' => $locked->headcount,
                ]);
            }

            return $locked;
        });
    }

    public function hiredCount(JobOpening $opening): int
    {
        return Application::query()->where('opening_id', $opening->id)->where('status', ApplicationStatus::Hired)->count();
    }

    /** The number of openings counted against the plan limit. */
    public function countedOpenings(): int
    {
        return JobOpening::query()->where('status', OpeningStatus::Open)->count();
    }

    /**
     * Validate references and compute the stored values (owner, placement, pipeline).
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function resolve(array $attributes, ?JobOpening $existing, ?TenantMembership $actor): array
    {
        $managerId = isset($attributes['hiring_manager_id']) && $attributes['hiring_manager_id'] !== '' ? (string) $attributes['hiring_manager_id'] : null;
        $manager = $managerId === null ? null : $this->employees->require($managerId, 'hiring_manager_id');

        $department = isset($attributes['department_id']) && $attributes['department_id'] !== '' ? (string) $attributes['department_id'] : null;
        $branch = isset($attributes['branch_id']) && $attributes['branch_id'] !== '' ? (string) $attributes['branch_id'] : null;

        if ($department !== null && Department::query()->whereKey($department)->doesntExist()) {
            throw ValidationException::withMessages(['department_id' => __('Choose a department of this workspace.')]);
        }

        if ($branch !== null && Branch::query()->whereKey($branch)->doesntExist()) {
            throw ValidationException::withMessages(['branch_id' => __('Choose a branch of this workspace.')]);
        }

        $department ??= $manager?->departmentId;
        $branch ??= $manager?->branchId;

        $pipelineId = isset($attributes['pipeline_id']) && $attributes['pipeline_id'] !== '' ? (string) $attributes['pipeline_id'] : null;

        if ($pipelineId !== null) {
            $pipeline = Pipeline::query()->find($pipelineId);

            if ($pipeline === null || (! $pipeline->is_active && $existing?->pipeline_id !== $pipelineId)) {
                throw ValidationException::withMessages(['pipeline_id' => __('Choose an active pipeline.')]);
            }
        } else {
            $pipelineId = $existing === null ? $this->catalogue->defaultPipeline()->id : $existing->pipeline_id;
        }

        // The owner is the hiring manager's account (so the `own` scope reaches their openings);
        // without one it is whoever created or last had it, never nobody.
        $ownerId = ($manager === null ? null : $manager->membershipId) ?? ($existing === null ? null : $existing->owner_id) ?? $actor?->id;

        return [
            'owner_id' => $ownerId,
            'department_id' => $department,
            'branch_id' => $branch,
            'pipeline_id' => $pipelineId,
            'hiring_manager_id' => $managerId,
            'title' => (string) $attributes['title'],
            'employment_type' => $attributes['employment_type'] ?? null,
            'location' => $attributes['location'] ?? null,
            'headcount' => (int) ($attributes['headcount'] ?? 1),
            'description' => $attributes['description'] ?? null,
            'requirements' => $attributes['requirements'] ?? null,
            'opens_on' => $attributes['opens_on'] ?? null,
            'closes_on' => $attributes['closes_on'] ?? null,
        ];
    }
}
