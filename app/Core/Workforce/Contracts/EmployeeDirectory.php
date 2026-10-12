<?php

declare(strict_types=1);

namespace App\Core\Workforce\Contracts;

use App\Core\Workforce\Data\EmployeeSnapshot;

/**
 * Read access to the workspace's employees for other optional modules.
 *
 * The implementation is bound by the `hrm` module. A module that uses it must
 * declare `hrm` in its manifest dependencies, so the entitlement resolver never
 * leaves it running without HRM. Every call is scoped to the active tenant and
 * answers null / an empty list for ids of another tenant.
 *
 * This is a system-level read: it is **not** narrowed by the signed-in person's
 * record scope. A consumer that shows data to people must apply its own access
 * rules and must never copy a snapshot into a table of its own as the source of
 * truth (hold the employee id and read again).
 */
interface EmployeeDirectory
{
    public function find(string $employeeId): ?EmployeeSnapshot;

    /**
     * @param  list<string>  $employeeIds
     * @return array<string, EmployeeSnapshot> keyed by employee id; unknown ids are left out
     */
    public function findMany(array $employeeIds): array;

    /** The employee whose workspace membership is the given one (the `TenantMembership` id). */
    public function findByMembership(string $membershipId): ?EmployeeSnapshot;

    /**
     * Employees ordered by id, for cursor paging.
     *
     * @param  array{status?: list<string>, department_id?: string, branch_id?: string, manager_id?: string, employment_type?: string, active_on?: string}  $filters
     *                                                                                                                                                                `active_on` (`Y-m-d`) keeps people employed on that local date (joined, not yet terminated).
     * @return list<EmployeeSnapshot>
     */
    public function list(array $filters = [], int $limit = 100, ?string $afterId = null): array;

    /** @return list<EmployeeSnapshot> */
    public function directReports(string $employeeId): array;

    /**
     * The chain of managers above an employee, nearest first, at most `$depth` long.
     *
     * @return list<EmployeeSnapshot>
     */
    public function managerChain(string $employeeId, int $depth = 10): array;
}
