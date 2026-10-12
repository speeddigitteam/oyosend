<?php

declare(strict_types=1);

namespace App\Modules\Talent\Actions;

use App\Core\Workforce\Contracts\EmployeeDirectory;
use App\Core\Workforce\Data\EmployeeSnapshot;
use Illuminate\Validation\ValidationException;

/** Resolves employee ids through the employee directory, with the validation error a form field needs. */
final class EmployeeLookup
{
    public function __construct(private readonly EmployeeDirectory $directory) {}

    public function find(string $employeeId): ?EmployeeSnapshot
    {
        return $this->directory->find($employeeId);
    }

    public function require(string $employeeId, string $field = 'employee_id', bool $allowTerminated = false): EmployeeSnapshot
    {
        $employee = $this->directory->find($employeeId);

        if ($employee === null || (! $allowTerminated && $employee->status === 'terminated')) {
            throw ValidationException::withMessages([$field => __('Choose an employee of this workspace.')]);
        }

        return $employee;
    }

    /**
     * @param  list<string>  $employeeIds
     * @return array<string, EmployeeSnapshot>
     */
    public function many(array $employeeIds): array
    {
        return $this->directory->findMany(array_values(array_unique($employeeIds)));
    }

    /**
     * Every employee, one page at a time (cursor on the id), for reports and launches.
     *
     * @param  array{status?: list<string>, department_id?: string, branch_id?: string, manager_id?: string, employment_type?: string, active_on?: string}  $filters
     * @return \Generator<int, EmployeeSnapshot>
     */
    public function each(array $filters = [], int $pageSize = 200, int $cap = 20000): \Generator
    {
        $after = null;
        $seen = 0;

        do {
            $page = $this->directory->list($filters, $pageSize, $after);

            foreach ($page as $employee) {
                yield $employee;
                $seen++;
            }

            $after = $page === [] ? null : $page[array_key_last($page)]->id;
        } while (count($page) === $pageSize && $seen < $cap);
    }
}
