<?php

declare(strict_types=1);

namespace App\Modules\Talent\Listeners;

use App\Core\Entitlements\Contracts\EntitlementChecker;
use App\Core\Events\DomainEvent;
use App\Core\Tenancy\TenantContext;
use App\Core\Workforce\Data\EmployeeSnapshot;
use App\Modules\Talent\Actions\ChecklistManager;
use App\Modules\Talent\Actions\EmployeeLookup;
use App\Modules\Talent\Actions\ProbationManager;
use App\Modules\Talent\Enums\ChecklistKind;
use App\Modules\Talent\Enums\ChecklistStatus;
use App\Modules\Talent\Enums\EnrolmentStatus;
use App\Modules\Talent\Enums\GoalStatus;
use App\Modules\Talent\Enums\ProbationStatus;
use App\Modules\Talent\Enums\ReviewStatus;
use App\Modules\Talent\Models\Checklist;
use App\Modules\Talent\Models\Enrolment;
use App\Modules\Talent\Models\Goal;
use App\Modules\Talent\Models\Probation;
use App\Modules\Talent\Models\Review;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Reacts to HRM's employee events (read by name and payload only: no HRM class is imported):
 *
 *  - `hrm.employee.created`    starts the default onboarding checklist and tracks probation;
 *  - `hrm.employee.updated`    keeps the owner/department/branch copy of Talent's employee records
 *                              and the probation mirror in step with HRM;
 *  - `hrm.employee.terminated` starts the default offboarding checklist, ends the probation and
 *                              cancels open training enrolments.
 *
 * Starting a checklist is idempotent through its unique source key, so a redelivered event never
 * creates a second one. A failure here is reported and never breaks the HRM action that raised it.
 */
final class SyncHrmEmployeeEvents
{
    public const string CREATED = 'hrm.employee.created';

    public const string UPDATED = 'hrm.employee.updated';

    public const string TERMINATED = 'hrm.employee.terminated';

    public function __construct(
        private readonly EntitlementChecker $entitlements,
        private readonly TenantContext $context,
        private readonly EmployeeLookup $employees,
        private readonly ChecklistManager $checklists,
        private readonly ProbationManager $probations,
    ) {}

    public function handle(DomainEvent $event): void
    {
        $name = $event::name();

        if (! in_array($name, [self::CREATED, self::UPDATED, self::TERMINATED], true)
            || ! $this->context->check()
            || $this->context->id() !== $event->tenantId()
            || ! $this->entitlements->allows($this->context->require(), 'talent')) {
            return;
        }

        $payload = $event->payload();
        $employeeId = (string) ($payload['employee_id'] ?? '');

        if ($employeeId === '') {
            return;
        }

        try {
            $employee = $this->employees->find($employeeId);

            if ($employee === null) {
                return;
            }

            match ($name) {
                self::CREATED => $this->created($employee),
                self::UPDATED => $this->updated($employee, (string) ($payload['changed'] ?? '')),
                default => $this->terminated($employee, (string) ($payload['terminated_on'] ?? $employee->terminatedOn ?? '')),
            };
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    private function created(EmployeeSnapshot $employee): void
    {
        $this->checklists->start($employee, ChecklistKind::Onboarding, null, self::CREATED.':'.$employee->id);
        $this->probations->track($employee);
    }

    private function updated(EmployeeSnapshot $employee, string $changed): void
    {
        $fields = array_filter(explode(',', $changed));

        if (array_intersect($fields, ['owner_id', 'department_id', 'branch_id']) !== []) {
            $this->replace($employee);
        }

        if (array_intersect($fields, ['status', 'probation_ends_on']) !== []) {
            $open = Probation::query()->where('employee_id', $employee->id)->where('status', ProbationStatus::InProgress)->first();

            if ($open !== null) {
                $this->probations->sync($open, $employee);
            } elseif ($employee->status === 'probation') {
                $this->probations->track($employee);
            }
        }
    }

    private function terminated(EmployeeSnapshot $employee, string $terminatedOn): void
    {
        $this->checklists->start($employee, ChecklistKind::Offboarding, null, self::TERMINATED.':'.$employee->id.':'.$terminatedOn);

        $open = Probation::query()->where('employee_id', $employee->id)->where('status', ProbationStatus::InProgress)->first();

        if ($open !== null) {
            $this->probations->close($open, ProbationStatus::Ended, $employee->terminatedOn);
        }

        Enrolment::query()->where('employee_id', $employee->id)->where('status', EnrolmentStatus::Enrolled)
            ->update(['status' => EnrolmentStatus::Cancelled, 'open_marker' => null]);
    }

    /**
     * Copy the employee's current account and placement onto their records. The owner follows
     * everywhere (so linking an account gives the person access to their history); department and
     * branch are changed only on records still in progress, closed history keeps the team it
     * happened in.
     */
    private function replace(EmployeeSnapshot $employee): void
    {
        $placement = [
            'owner_id' => $employee->membershipId,
            'department_id' => $employee->departmentId,
            'branch_id' => $employee->branchId,
        ];
        $owner = ['owner_id' => $employee->membershipId];

        DB::transaction(function () use ($employee, $placement, $owner): void {
            Review::query()->where('employee_id', $employee->id)->update($owner);
            Review::query()->where('employee_id', $employee->id)
                ->whereNotIn('status', [ReviewStatus::Calibrated, ReviewStatus::Acknowledged])->update($placement);
            Goal::query()->where('employee_id', $employee->id)->update($owner);
            Goal::query()->where('employee_id', $employee->id)->where('status', GoalStatus::Active)->update($placement);
            Enrolment::query()->where('employee_id', $employee->id)->update($owner);
            Enrolment::query()->where('employee_id', $employee->id)->where('status', EnrolmentStatus::Enrolled)->update($placement);
            Checklist::query()->where('employee_id', $employee->id)->update($owner);
            Checklist::query()->where('employee_id', $employee->id)->where('status', ChecklistStatus::Open)->update($placement);
            Probation::query()->where('employee_id', $employee->id)->update($owner);
            Probation::query()->where('employee_id', $employee->id)->where('status', ProbationStatus::InProgress)->update($placement);
        });
    }
}
