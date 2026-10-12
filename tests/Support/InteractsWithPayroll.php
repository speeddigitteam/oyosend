<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Core\Identity\Models\TenantMembership;
use App\Core\Identity\Models\User;
use App\Core\Tenancy\Models\Tenant;
use App\Core\Workforce\Contracts\EmployeeDirectory;
use App\Modules\Hrm\Actions\AttendanceCloser;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Payroll\Actions\PeriodManager;
use App\Modules\Payroll\Actions\SalaryManager;
use App\Modules\Payroll\Actions\StructureManager;
use App\Modules\Payroll\Models\Period;
use App\Modules\Payroll\Models\SalaryAssignment;
use App\Modules\Payroll\Models\SalaryStructure;
use Tests\TestCase;

/**
 * Building blocks for Payroll tests: a workspace entitled to `hrm` and `payroll`, a salary structure
 * with components, a salary revision, a pay period and closed attendance days. Employees come from
 * the HRM helpers (tests may use HRM's own actions; the Payroll module itself never does).
 *
 * @mixin TestCase
 */
trait InteractsWithPayroll
{
    use InteractsWithHrm;

    protected function payrollTenant(string $slug = 'pay-ws'): Tenant
    {
        $tenant = $this->hrmTenant($slug);
        $this->entitle($tenant, 'payroll');

        return $tenant;
    }

    /**
     * @param  list<array<string, mixed>>  $components
     */
    protected function payStructure(Tenant $tenant, array $components = [], string $name = 'Standard'): SalaryStructure
    {
        return $this->inTenant($tenant, function () use ($components, $name): SalaryStructure {
            $manager = app(StructureManager::class);
            $structure = $manager->saveStructure(null, ['name' => $name, 'is_active' => true]);

            foreach ($components as $component) {
                $manager->saveComponent($structure, null, [
                    'kind' => 'earning', 'calc' => 'fixed', 'is_taxable' => true, 'is_active' => true, 'sort_order' => 0,
                    ...$component,
                ]);
            }

            return $structure;
        });
    }

    protected function paySalary(Tenant $tenant, Employee $employee, SalaryStructure $structure, int $basic, string $from = '2026-01-01'): SalaryAssignment
    {
        return $this->inTenant($tenant, function () use ($employee, $structure, $basic, $from): SalaryAssignment {
            $snapshot = app(EmployeeDirectory::class)->find($employee->id);

            return app(SalaryManager::class)->assign($snapshot, $structure, $basic, $from, null);
        });
    }

    /** @param array<string, mixed> $attributes */
    protected function payPeriod(Tenant $tenant, array $attributes = []): Period
    {
        return $this->inTenant($tenant, fn (): Period => app(PeriodManager::class)->create([
            'kind' => 'custom', 'starts_on' => '2026-03-01', 'ends_on' => '2026-03-07', ...$attributes,
        ]));
    }

    /** Close every attendance day of the employee up to a local date (HRM's own closer). */
    protected function closeDays(Tenant $tenant, Employee $employee, string $upTo): void
    {
        $this->inTenant($tenant, fn () => app(AttendanceCloser::class)->closeEmployee($employee, $upTo));
    }

    protected function payMembership(Tenant $tenant, User $user): TenantMembership
    {
        return $this->membershipOf($tenant, $user);
    }

    protected function payUrl(Tenant $tenant, string $path): string
    {
        return $this->tenantUrl($tenant, '/payroll'.$path);
    }
}
