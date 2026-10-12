<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Listeners;

use App\Core\Entitlements\Contracts\EntitlementChecker;
use App\Core\Events\DomainEvent;
use App\Core\Tenancy\TenantContext;
use App\Modules\Payroll\Actions\Profiles;

/**
 * Keeps Payroll's copy of an employee's placement and account link current when HRM announces a
 * change (`hrm.employee.created|updated|terminated`). It reads only the documented payload (the
 * employee id) and re-reads the employee through the `EmployeeDirectory` contract.
 */
final class RefreshEmployeePlacement
{
    public const array EVENTS = ['hrm.employee.created', 'hrm.employee.updated', 'hrm.employee.terminated'];

    public function __construct(
        private readonly EntitlementChecker $entitlements,
        private readonly TenantContext $context,
        private readonly Profiles $profiles,
    ) {}

    public function handle(DomainEvent $event): void
    {
        if (! in_array($event::name(), self::EVENTS, true) || ! $this->context->check() || $this->context->id() !== $event->tenantId()) {
            return;
        }

        if (! $this->entitlements->allows($this->context->require(), 'payroll')) {
            return;
        }

        $employeeId = $event->payload()['employee_id'] ?? null;

        if (is_string($employeeId) && $employeeId !== '') {
            $this->profiles->refresh($employeeId);
        }
    }
}
