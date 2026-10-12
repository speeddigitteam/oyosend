<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Core\Access\RoleManager;
use App\Core\Identity\Models\TenantMembership;
use App\Core\Identity\Models\User;
use App\Core\Tenancy\Models\Tenant;
use App\Modules\Hrm\Actions\EmployeeManager;
use App\Modules\Hrm\Actions\HrmCatalogue;
use App\Modules\Hrm\Actions\ShiftManager;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Models\LeavePolicy;
use App\Modules\Hrm\Models\LeaveType;
use App\Modules\Hrm\Models\Shift;
use Carbon\CarbonImmutable;
use Tests\TestCase;

/**
 * Building blocks for HRM tests: a workspace entitled to `hrm`, employees (optionally with a
 * workspace account), role grants with scopes, a default shift, and a clock in local time.
 *
 * @mixin TestCase
 */
trait InteractsWithHrm
{
    protected function hrmTenant(string $slug = 'hrm-ws'): Tenant
    {
        $tenant = $this->createTenant($slug, ['timezone' => 'Asia/Dhaka', 'currency' => 'BDT']);
        $this->entitle($tenant, 'hrm');

        return $tenant;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function hire(Tenant $tenant, array $overrides = []): Employee
    {
        return $this->inTenant($tenant, fn (): Employee => app(EmployeeManager::class)->create([
            'first_name' => 'Rafi',
            'last_name' => 'Ahmed',
            'employment_type' => 'full_time',
            'status' => 'active',
            'joined_on' => '2026-01-05',
            ...$overrides,
        ]));
    }

    /**
     * A workspace member holding exactly the given permissions (scopes: permission => own|department|branch|all).
     *
     * @param  list<string>  $permissions
     * @param  array<string, string>  $scopes
     * @return array{0: User, 1: TenantMembership}
     */
    protected function hrmMember(Tenant $tenant, array $permissions, array $scopes = [], ?string $roleName = null): array
    {
        $owner = $this->ownerOf($tenant);
        $role = $this->inTenant($tenant, fn () => app(RoleManager::class)->create(
            $owner,
            $roleName ?? 'Role '.bin2hex(random_bytes(4)),
            null,
            $permissions,
            $scopes,
        ));
        $user = $this->addMember($tenant, $role);

        return [$user, $this->membershipOf($tenant, $user)];
    }

    /**
     * An employee that is also a workspace member with the given permissions.
     *
     * @param  list<string>  $permissions
     * @param  array<string, string>  $scopes
     * @param  array<string, mixed>  $employee
     * @return array{0: User, 1: Employee, 2: TenantMembership}
     */
    protected function hrmStaff(Tenant $tenant, array $permissions, array $scopes = [], array $employee = []): array
    {
        [$user, $membership] = $this->hrmMember($tenant, $permissions, $scopes);
        $record = $this->hire($tenant, ['membership_id' => $membership->id, ...$employee]);

        return [$user, $record, $membership];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function hrmShift(Tenant $tenant, array $overrides = []): Shift
    {
        return $this->inTenant($tenant, fn (): Shift => app(ShiftManager::class)->save(null, [
            'name' => 'Day',
            'starts_at' => '09:00',
            'ends_at' => '18:00',
            'break_minutes' => 60,
            'late_grace_minutes' => 10,
            'early_grace_minutes' => 0,
            'overtime_threshold_minutes' => 0,
            'half_day_minutes' => null,
            'weekly_off_days' => null,
            'is_default' => true,
            'is_active' => true,
            ...$overrides,
        ]));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function hrmLeaveType(Tenant $tenant, array $overrides = []): LeaveType
    {
        return $this->inTenant($tenant, fn (): LeaveType => app(HrmCatalogue::class)->saveLeaveType(null, [
            'name' => 'Annual leave',
            'code' => 'AL',
            'is_paid' => true,
            'tracks_balance' => true,
            'allows_half_day' => true,
            'requires_reason' => false,
            'max_consecutive_days' => null,
            'min_notice_days' => 0,
            'approval_flow' => 'manager_then_hr',
            'is_active' => true,
            'sort_order' => 0,
            ...$overrides,
        ]));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function hrmPolicy(Tenant $tenant, LeaveType $type, array $overrides = []): LeavePolicy
    {
        return $this->inTenant($tenant, fn () => app(HrmCatalogue::class)->saveLeavePolicy(null, [
            'leave_type_id' => $type->id,
            'name' => 'Standard',
            'employment_types' => null,
            'accrual' => 'annual',
            'annual_units' => 20,
            'carry_forward_max_units' => 0,
            'max_balance_units' => null,
            'max_units_per_request' => null,
            'sandwich_rule' => false,
            'min_service_days' => 0,
            'is_active' => true,
            ...$overrides,
        ]));
    }

    /** Set the clock to a local Dhaka date and time. */
    protected function hrmAt(string $local): void
    {
        $this->travelTo(CarbonImmutable::parse($local, 'Asia/Dhaka'));
    }

    protected function hrmUrl(Tenant $tenant, string $path): string
    {
        return $this->tenantUrl($tenant, '/hrm'.$path);
    }
}
